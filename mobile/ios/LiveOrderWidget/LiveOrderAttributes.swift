import ActivityKit
import Foundation

/// The order as a Live Activity (lock screen and Dynamic Island).
///
/// THIS FILE BELONGS TO TWO TARGETS: the widget extension (it draws the activity) and Runner (the app starts and updates it).
/// In Xcode, select the file and tick both targets under "Target Membership" (docs/guides/IOS_LIVE_ACTIVITY.md, step 5).
///
/// The keys below are the contract with the server (`App\Domain\Notifications\Services\LiveActivityPusher`): the JSON of a push
/// (`content-state`, and `attributes` for a remote start) must use exactly these names. Every time is Unix seconds, so no date
/// decoding strategy is involved; a value the order does not have is simply left out, which is why those are optionals.
@available(iOS 16.1, *)
struct LiveOrderAttributes: ActivityAttributes {
    /// What changes while the order moves.
    struct ContentState: Codable, Hashable {
        /// placed, preparing, on_the_way, ready, delivered, collected, cancelled, failed
        var phase: String
        /// Finer state (driver_assigned, out_for_delivery ...), for the server's records.
        var detail: String
        /// The stage the bar stops at, 0...3 of `total` (four).
        var step: Int
        var total: Int
        /// How far through the current stage the order is, 0...1. Absent when there is nothing to measure against.
        var sub: Double?
        /// Headline and status line, written by the server in the customer's language ("Arrives at 6:35pm-6:55pm").
        var title: String
        var body: String
        /// Short name of the current stage ("Preparing").
        var stepLabel: String
        /// The arrival window as text ("6:35pm-6:55pm").
        var etaLabel: String?
        var etaFrom: Int?
        var etaTo: Int?
        /// The driver's expected arrival as one moment, only while on the road: the activity counts down to it by itself.
        var etaAt: Int?
        var driverFirstName: String?
        /// The order is over (delivered, collected, cancelled, failed).
        var done: Bool
        /// `en` or `ar`: the language of the texts, and the reading direction of the activity.
        var lang: String

        enum CodingKeys: String, CodingKey {
            case phase, detail, step, total, sub, title, body, done, lang
            case stepLabel = "step_label"
            case etaLabel = "eta_label"
            case etaFrom = "eta_from"
            case etaTo = "eta_to"
            case etaAt = "eta_at"
            case driverFirstName = "driver_first_name"
        }
    }

    // Fixed for the life of the activity.
    var orderId: String
    var number: String
    /// `delivery` or `pickup`.
    var type: String
    /// The live theme's colours as "#RRGGBB" (seasonal looks recolour the activity).
    var brandPrimary: String
    var brandAccent: String

    enum CodingKeys: String, CodingKey {
        case number, type
        case orderId = "order_id"
        case brandPrimary = "brand_primary"
        case brandAccent = "brand_accent"
    }
}

@available(iOS 16.1, *)
extension LiveOrderAttributes {
    var pickup: Bool { type == "pickup" }
}

@available(iOS 16.1, *)
extension LiveOrderAttributes.ContentState {
    /// A stopped order keeps the stage it reached but is not on its way any more.
    var isStopped: Bool { phase == "cancelled" || phase == "failed" }
}
