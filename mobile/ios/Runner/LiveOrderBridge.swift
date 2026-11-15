import ActivityKit
import Flutter
import Foundation

/// The iPhone side of `lib/core/live_order/live_activity_platform.dart`: starts, updates and ends the order's Live Activity (lock screen
/// and Dynamic Island) while the app is on screen, and reports Apple's push tokens to Dart, which hands them to the server so it can keep
/// the activity moving while the app is closed.
///
/// Channel `albahar/live_activity`, Dart to Swift:
///  - `isAvailable` -> Bool (iOS 16.2+ and Live Activities allowed in the iPhone settings)
///  - `show(map)` -> `started | updated | ended | unavailable | invalid` (the map is the card: see LiveOrder.toChannelMap, plus
///    `brand_accent` and `step_label`); a finished order shows its last state and ends 30 minutes later
///  - `end(orderId)`, `endAll()`
///  - `pendingTokens()` -> [{kind: activity|start, order_id?, token, environment}] (the newest token per activity and for the phone)
/// Swift to Dart: `token({kind, order_id?, token, environment})` whenever Apple gives or changes one.
///
/// Everything degrades silently: iOS before 16.2, Live Activities switched off, or a build without the widget extension all answer
/// "unavailable", and the app falls back to its usual notification. This file belongs to the Runner target only. `AppDelegate` finds the
/// class by name (`NSClassFromString`), so the app builds the same with or without this file in the project
/// (docs/guides/IOS_LIVE_ACTIVITY.md).
@objc(LiveOrderBridge)
@MainActor
final class LiveOrderBridge: NSObject {
    private static var host: AnyObject?

    /// Called by `AppDelegate` once the Flutter engine exists.
    @objc(registerWithMessenger:)
    static func register(messenger: FlutterBinaryMessenger) {
        let channel = FlutterMethodChannel(name: "albahar/live_activity", binaryMessenger: messenger)
        if #available(iOS 16.2, *) {
            let activityHost = LiveOrderActivityHost(channel: channel)
            host = activityHost
            channel.setMethodCallHandler { call, result in
                activityHost.handle(call, result: result)
            }
        } else {
            channel.setMethodCallHandler { call, result in
                switch call.method {
                case "isAvailable": result(false)
                case "show": result("unavailable")
                case "pendingTokens": result([])
                default: result(nil)
                }
            }
        }
    }
}

@available(iOS 16.2, *)
@MainActor
final class LiveOrderActivityHost {
    private let channel: FlutterMethodChannel

    /// The newest token per slot ("start" for the phone, "activity:<order id>"), kept for `pendingTokens`.
    private var latest: [String: [String: Any]] = [:]
    private var tokenTasks: [String: Task<Void, Never>] = [:]

    /// Observers that live as long as the app (they are never cancelled: this host is created once, at start-up).
    private var updatesTask: Task<Void, Never>?
    private var startTokenTask: Task<Void, Never>?

    /// Which Apple servers this build's push tokens belong to. It is a property of how the app was signed: a development profile (a build
    /// run from Xcode) uses Apple's sandbox; TestFlight, the App Store and Ad Hoc builds use production. The profile is read from the
    /// app bundle (`embedded.mobileprovision`); App Store and TestFlight builds carry none, which is also "production". (A compile flag
    /// such as DEBUG would not do: it says nothing about the signing.)
    private static let environment: String = {
        guard
            let url = Bundle.main.url(forResource: "embedded", withExtension: "mobileprovision"),
            let data = try? Data(contentsOf: url),
            let text = String(data: data, encoding: .isoLatin1),
            let start = text.range(of: "<?xml"),
            let end = text.range(of: "</plist>"),
            start.lowerBound < end.upperBound,
            let xml = String(text[start.lowerBound..<end.upperBound]).data(using: .isoLatin1),
            let plist = try? PropertyListSerialization.propertyList(from: xml, options: [], format: nil) as? [String: Any],
            let entitlements = plist["Entitlements"] as? [String: Any],
            let aps = entitlements["aps-environment"] as? String
        else { return "production" }
        return aps == "development" ? "sandbox" : "production"
    }()

    /// How long a finished order stays on the lock screen.
    private static let finishedStays: TimeInterval = 30 * 60

    init(channel: FlutterMethodChannel) {
        self.channel = channel
        // Activities that survived the app being closed, and activities Apple starts remotely (push-to-start): their tokens are ours to report.
        for activity in Activity<LiveOrderAttributes>.activities { observe(activity) }
        updatesTask = Task { [weak self] in
            for await activity in Activity<LiveOrderAttributes>.activityUpdates {
                self?.observe(activity)
            }
        }
        if #available(iOS 17.2, *) {
            if let data = Activity<LiveOrderAttributes>.pushToStartToken { record(kind: "start", orderId: nil, token: data) }
            startTokenTask = Task { [weak self] in
                for await data in Activity<LiveOrderAttributes>.pushToStartTokenUpdates {
                    self?.record(kind: "start", orderId: nil, token: data)
                }
            }
        }
    }

    func handle(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
        switch call.method {
        case "isAvailable":
            result(ActivityAuthorizationInfo().areActivitiesEnabled)
        case "show":
            guard let map = call.arguments as? [String: Any] else { return result("invalid") }
            Task { result(await self.show(map)) }
        case "end":
            guard let orderId = call.arguments as? String else { return result(nil) }
            Task {
                await self.end(orderId: orderId)
                result(nil)
            }
        case "endAll":
            Task {
                await self.endAll()
                result(nil)
            }
        case "pendingTokens":
            result(Array(latest.values))
        default:
            result(FlutterMethodNotImplemented)
        }
    }

    // MARK: - Start, update, end

    private func show(_ map: [String: Any]) async -> String {
        guard let order = LiveOrderPayload(map) else { return "invalid" }
        let content = ActivityContent(state: order.state, staleDate: nil)
        if let running = Activity<LiveOrderAttributes>.activities.first(where: { $0.attributes.orderId == order.attributes.orderId }) {
            observe(running)
            if order.state.done {
                await running.end(content, dismissalPolicy: .after(Date().addingTimeInterval(Self.finishedStays)))
                return "ended"
            }
            await running.update(content)
            return "updated"
        }
        // Nothing to start for a finished order, and nothing to start when the customer switched Live Activities off.
        guard !order.state.done, ActivityAuthorizationInfo().areActivitiesEnabled else { return "unavailable" }
        do {
            let activity = try Activity.request(attributes: order.attributes, content: content, pushType: .token)
            observe(activity)
            return "started"
        } catch {
            // No widget extension in this build, the system refused (too many activities), or the app is not in the foreground.
            return "unavailable"
        }
    }

    private func end(orderId: String) async {
        for activity in Activity<LiveOrderAttributes>.activities where activity.attributes.orderId == orderId {
            await activity.end(nil, dismissalPolicy: .immediate)
        }
    }

    private func endAll() async {
        for activity in Activity<LiveOrderAttributes>.activities {
            await activity.end(nil, dismissalPolicy: .immediate)
        }
        latest = latest.filter { $0.key == "start" }
    }

    // MARK: - Tokens

    /// Reports the push token of an activity now and whenever Apple changes it, until the activity is over.
    private func observe(_ activity: Activity<LiveOrderAttributes>) {
        let key = activity.id
        guard tokenTasks[key] == nil else { return }
        let orderId = activity.attributes.orderId
        if let data = activity.pushToken { record(kind: "activity", orderId: orderId, token: data) }
        tokenTasks[key] = Task { [weak self] in
            for await data in activity.pushTokenUpdates {
                self?.record(kind: "activity", orderId: orderId, token: data)
            }
        }
        Task { [weak self] in
            for await state in activity.activityStateUpdates where state == .ended || state == .dismissed {
                self?.tokenTasks[key]?.cancel()
                self?.tokenTasks[key] = nil
                self?.latest["activity:\(orderId)"] = nil
                return
            }
        }
    }

    private func record(kind: String, orderId: String?, token: Data) {
        let hex = token.map { String(format: "%02x", $0) }.joined()
        var event: [String: Any] = ["kind": kind, "token": hex, "environment": Self.environment]
        if let orderId = orderId { event["order_id"] = orderId }
        latest[orderId.map { "activity:\($0)" } ?? "start"] = event
        channel.invokeMethod("token", arguments: event)
    }
}

// MARK: - The card as it comes from Dart

/// The Dart card (`LiveOrder.toChannelMap`) turned into the activity's attributes and content. The driver's phone number is never read:
/// a Live Activity is readable on a locked screen.
@available(iOS 16.2, *)
struct LiveOrderPayload {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState

    private static let finalPhases: Set<String> = ["delivered", "collected", "cancelled", "failed"]

    init?(_ map: [String: Any]) {
        guard let orderId = map["order_id"] as? String, !orderId.isEmpty else { return nil }
        let phase = (map["phase"] as? String) ?? "placed"
        let number = (map["number"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? orderId
        attributes = LiveOrderAttributes(
            orderId: orderId,
            number: number,
            type: (map["type"] as? String) == "pickup" ? "pickup" : "delivery",
            brandPrimary: Self.hex(map["brand_primary"]) ?? "#8A1538",
            brandAccent: Self.hex(map["brand_accent"]) ?? "#B3254F"
        )
        let done = Self.finalPhases.contains(phase)
        state = LiveOrderAttributes.ContentState(
            phase: phase,
            detail: phase,
            step: min(max((map["step"] as? Int) ?? 0, 0), 3),
            total: 4,
            sub: done ? nil : (map["sub"] as? Double),
            title: Self.text(map["headline"]) ?? Self.text(map["title"]) ?? "",
            body: Self.text(map["subtitle"]) ?? Self.text(map["body"]) ?? "",
            stepLabel: Self.text(map["step_label"]) ?? "",
            etaLabel: Self.text(map["eta_label"]),
            etaFrom: Self.seconds(map["eta_from_ms"]),
            etaTo: Self.seconds(map["eta_to_ms"]),
            etaAt: done ? nil : Self.seconds(map["eta_at_ms"]),
            driverFirstName: done ? nil : Self.text(map["driver_name"]),
            done: done,
            lang: (map["locale"] as? String) == "ar" ? "ar" : "en"
        )
    }

    private static func text(_ value: Any?) -> String? {
        guard let s = (value as? String)?.trimmingCharacters(in: .whitespacesAndNewlines), !s.isEmpty else { return nil }
        return s
    }

    /// Milliseconds since 1970 (Dart sends integers) as whole seconds; nil when absent or not positive.
    private static func seconds(_ value: Any?) -> Int? {
        guard let ms = (value as? NSNumber)?.int64Value, ms > 0 else { return nil }
        return Int(ms / 1000)
    }

    /// An ARGB integer from Dart (0xFF8A1538) as "#8A1538".
    private static func hex(_ value: Any?) -> String? {
        guard let argb = (value as? NSNumber)?.int64Value else { return nil }
        return String(format: "#%06X", UInt32(truncatingIfNeeded: argb & 0xFFFFFF))
    }
}
