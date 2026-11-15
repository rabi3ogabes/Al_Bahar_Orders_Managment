import ActivityKit
import SwiftUI
import WidgetKit

// MARK: - The Al Bahar mark (sun over two waves), drawn, so the extension needs no image file

/// One wave of the logo: two full periods across the width, one stroke.
struct LiveOrderWave: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()
        let half = rect.width / 4
        let amplitude = rect.height / 2
        path.move(to: CGPoint(x: rect.minX, y: rect.midY))
        for i in 0..<4 {
            let x0 = rect.minX + half * CGFloat(i)
            let direction: CGFloat = i % 2 == 0 ? -1 : 1
            path.addCurve(
                to: CGPoint(x: x0 + half, y: rect.midY),
                control1: CGPoint(x: x0 + half * 0.33, y: rect.midY + direction * amplitude * 1.33),
                control2: CGPoint(x: x0 + half * 0.66, y: rect.midY + direction * amplitude * 1.33)
            )
        }
        return path
    }
}

/// The brand mark in one colour: the sun and the two waves of the Al Bahar logo.
struct LiveOrderBrandMark: View {
    var size: CGFloat
    var tint: Color

    var body: some View {
        ZStack {
            Circle()
                .fill(tint)
                .frame(width: size * 0.3, height: size * 0.3)
                .offset(y: -size * 0.24)
            LiveOrderWave()
                .stroke(tint, style: StrokeStyle(lineWidth: size * 0.075, lineCap: .round, lineJoin: .round))
                .frame(width: size * 0.75, height: size * 0.12)
                .offset(y: size * 0.06)
            LiveOrderWave()
                .stroke(tint, style: StrokeStyle(lineWidth: size * 0.075, lineCap: .round, lineJoin: .round))
                .frame(width: size * 0.75, height: size * 0.12)
                .offset(y: size * 0.26)
        }
        .frame(width: size, height: size)
        .accessibilityHidden(true)
    }
}

// MARK: - The four-stage progress bar

@available(iOS 16.1, *)
struct LiveOrderProgressBar: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState
    let palette: LiveOrderPalette

    private let stages = 4
    private let circle: CGFloat = 24
    private let thickness: CGFloat = 4

    var body: some View {
        HStack(spacing: 6) {
            ForEach(0..<stages, id: \.self) { index in
                LiveOrderStageCircle(index: index, attributes: attributes, state: state, palette: palette, diameter: circle)
                if index < stages - 1 {
                    LiveOrderSegment(fraction: fill(of: index), palette: palette, thickness: thickness)
                }
            }
        }
        .frame(height: circle + 4)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(
            Text(verbatim: LiveOrderStrings.progressDescription(
                step: state.step, total: stages, pickup: attributes.pickup, stopped: state.isStopped, lang: state.lang))
        )
    }

    /// How full the segment after stage [index] is: whole for stages reached, the share of the current stage for the next one.
    private func fill(of index: Int) -> Double {
        if state.done && !state.isStopped { return 1 }
        if index < state.step { return 1 }
        if index == state.step && !state.isStopped { return min(max(state.sub ?? 0.15, 0), 1) }
        return 0
    }
}

@available(iOS 16.1, *)
struct LiveOrderSegment: View {
    let fraction: Double
    let palette: LiveOrderPalette
    let thickness: CGFloat

    var body: some View {
        GeometryReader { geometry in
            ZStack(alignment: .leading) {
                Capsule().fill(palette.track)
                if fraction > 0 {
                    // Never narrower than its own height, so a first hint of progress still reads as a pill.
                    Capsule().fill(palette.fill).frame(width: max(thickness, geometry.size.width * CGFloat(fraction)))
                }
            }
            .frame(height: thickness)
            .frame(maxHeight: .infinity)
        }
    }
}

@available(iOS 16.1, *)
struct LiveOrderStageCircle: View {
    let index: Int
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState
    let palette: LiveOrderPalette
    let diameter: CGFloat

    private var done: Bool { (state.done && !state.isStopped) || index < state.step }
    private var current: Bool { index == state.step && !done }

    var body: some View {
        ZStack {
            if done || current {
                Circle().fill(palette.fill)
                if current && !state.isStopped {
                    // A soft ring marks where the order is now.
                    Circle().stroke(palette.fill.opacity(0.35), lineWidth: 1.5).frame(width: diameter + 4, height: diameter + 4)
                }
                glyph.foregroundColor(palette.onFill).font(.system(size: diameter * 0.5, weight: .semibold))
            } else {
                glyph.foregroundColor(palette.muted).font(.system(size: diameter * 0.62, weight: .regular))
            }
        }
        .frame(width: diameter, height: diameter)
    }

    @ViewBuilder
    private var glyph: some View {
        if done {
            Image(systemName: "checkmark")
        } else if current && state.isStopped {
            Image(systemName: "xmark")
        } else {
            Image(systemName: LiveOrderStageCircle.symbol(index, pickup: attributes.pickup)).flipsForRightToLeftLayoutDirection(true)
        }
    }

    /// check (placed), store (preparing), truck or bag (on the way / ready), pin or check (delivered / collected).
    static func symbol(_ index: Int, pickup: Bool) -> String {
        switch index {
        case 0: return "checkmark"
        case 1: return "storefront"
        case 2: return pickup ? "bag" : "box.truck"
        default: return pickup ? "checkmark" : "mappin.and.ellipse"
        }
    }
}

// MARK: - Countdown and chip

@available(iOS 16.1, *)
extension LiveOrderAttributes.ContentState {
    /// The moment the driver arrives, while it is still ahead.
    var arrival: Date? {
        guard !done, let at = etaAt else { return nil }
        let date = Date(timeIntervalSince1970: TimeInterval(at))
        return date > Date() ? date : nil
    }
}

/// A timer that counts down to [date] by itself (no update needed every second); stops at zero.
@available(iOS 16.1, *)
struct LiveOrderCountdown: View {
    let date: Date

    var body: some View {
        let now = Date()
        Text(timerInterval: now...max(now, date), pauseTime: nil, countsDown: true, showsHours: false)
            .monospacedDigit()
            .multilineTextAlignment(.trailing)
    }
}

@available(iOS 16.1, *)
struct LiveOrderDriverChip: View {
    let name: String
    let lang: String
    let palette: LiveOrderPalette

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: "box.truck").font(.system(size: 11)).flipsForRightToLeftLayoutDirection(true)
            Text(verbatim: LiveOrderStrings.driver(name, lang: lang)).font(.system(size: 12)).lineLimit(1)
        }
        .foregroundColor(palette.secondaryText)
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(Capsule().fill(palette.chip))
    }
}

// MARK: - Lock screen

@available(iOS 16.1, *)
struct LiveOrderLockScreenView: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        let palette = LiveOrderPalette(brandPrimary: attributes.brandPrimary, dark: colorScheme == .dark, stopped: state.isStopped)
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .top, spacing: 12) {
                LiveOrderBrandMark(size: 28, tint: palette.fill)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: state.title)
                        .font(.system(size: 17, weight: .semibold))
                        .foregroundColor(palette.primaryText)
                        .lineLimit(2)
                    Text(verbatim: state.body)
                        .font(.system(size: 14))
                        .foregroundColor(palette.secondaryText)
                        .lineLimit(2)
                }
                Spacer(minLength: 8)
                if let arrival = state.arrival {
                    VStack(alignment: .trailing, spacing: 0) {
                        LiveOrderCountdown(date: arrival)
                            .font(.system(size: 20, weight: .semibold))
                            .foregroundColor(palette.fill)
                        Text(verbatim: LiveOrderStrings.arrivesIn(lang: state.lang))
                            .font(.system(size: 11))
                            .foregroundColor(palette.secondaryText)
                    }
                    .frame(maxWidth: 76, alignment: .trailing)
                }
            }
            LiveOrderProgressBar(attributes: attributes, state: state, palette: palette)
            if let name = state.driverFirstName, !state.done {
                LiveOrderDriverChip(name: name, lang: state.lang, palette: palette)
            }
        }
        .padding(16)
        .liveOrderLanguage(state.lang)
    }
}

// MARK: - Dynamic Island

/// The island is always dark, so its views use the dark palette whatever the lock screen does.
@available(iOS 16.1, *)
enum LiveOrderIsland {
    static func palette(_ attributes: LiveOrderAttributes, _ state: LiveOrderAttributes.ContentState) -> LiveOrderPalette {
        LiveOrderPalette(brandPrimary: attributes.brandPrimary, dark: true, stopped: state.isStopped)
    }

    static func symbol(_ attributes: LiveOrderAttributes, _ state: LiveOrderAttributes.ContentState) -> String {
        if state.isStopped { return "xmark" }
        if state.done { return "checkmark" }
        return LiveOrderStageCircle.symbol(state.step, pickup: attributes.pickup)
    }
}

@available(iOS 16.1, *)
struct LiveOrderIslandLeading: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState

    var body: some View {
        LiveOrderBrandMark(size: 28, tint: LiveOrderIsland.palette(attributes, state).fill)
            .liveOrderLanguage(state.lang)
    }
}

@available(iOS 16.1, *)
struct LiveOrderIslandTrailing: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState

    var body: some View {
        let palette = LiveOrderIsland.palette(attributes, state)
        Group {
            if let arrival = state.arrival {
                LiveOrderCountdown(date: arrival).font(.system(size: 20, weight: .semibold)).foregroundColor(palette.fill)
            } else {
                Image(systemName: LiveOrderIsland.symbol(attributes, state)).font(.system(size: 18, weight: .semibold))
                    .foregroundColor(palette.fill).flipsForRightToLeftLayoutDirection(true)
            }
        }
        .liveOrderLanguage(state.lang)
    }
}

@available(iOS 16.1, *)
struct LiveOrderIslandCenter: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState

    var body: some View {
        Text(verbatim: state.title)
            .font(.system(size: 15, weight: .semibold))
            .foregroundColor(.white)
            .lineLimit(1)
            .minimumScaleFactor(0.8)
            .liveOrderLanguage(state.lang)
    }
}

@available(iOS 16.1, *)
struct LiveOrderIslandBottom: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState

    var body: some View {
        let palette = LiveOrderIsland.palette(attributes, state)
        VStack(alignment: .leading, spacing: 8) {
            LiveOrderProgressBar(attributes: attributes, state: state, palette: palette)
            HStack(spacing: 8) {
                Text(verbatim: state.body)
                    .font(.system(size: 13))
                    .foregroundColor(palette.secondaryText)
                    .lineLimit(1)
                Spacer(minLength: 0)
                if let name = state.driverFirstName, !state.done {
                    LiveOrderDriverChip(name: name, lang: state.lang, palette: palette)
                }
            }
        }
        .liveOrderLanguage(state.lang)
    }
}

/// Compact leading: only the brand mark (one logo, small).
@available(iOS 16.1, *)
struct LiveOrderCompactLeading: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState

    var body: some View {
        LiveOrderBrandMark(size: 20, tint: LiveOrderIsland.palette(attributes, state).fill)
    }
}

/// Compact trailing: the countdown while the driver is on the road, else the stage glyph.
@available(iOS 16.1, *)
struct LiveOrderCompactTrailing: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState

    var body: some View {
        let palette = LiveOrderIsland.palette(attributes, state)
        Group {
            if let arrival = state.arrival {
                LiveOrderCountdown(date: arrival).font(.system(size: 13, weight: .semibold)).foregroundColor(palette.fill).frame(maxWidth: 48)
            } else {
                Image(systemName: LiveOrderIsland.symbol(attributes, state)).font(.system(size: 13, weight: .semibold))
                    .foregroundColor(palette.fill).flipsForRightToLeftLayoutDirection(true)
            }
        }
        .liveOrderLanguage(state.lang)
    }
}

/// Minimal: the stage glyph in the brand colour (another app's activity has the other half of the island).
@available(iOS 16.1, *)
struct LiveOrderMinimal: View {
    let attributes: LiveOrderAttributes
    let state: LiveOrderAttributes.ContentState

    var body: some View {
        Image(systemName: LiveOrderIsland.symbol(attributes, state))
            .font(.system(size: 13, weight: .semibold))
            .foregroundColor(LiveOrderIsland.palette(attributes, state).fill)
            .flipsForRightToLeftLayoutDirection(true)
    }
}
