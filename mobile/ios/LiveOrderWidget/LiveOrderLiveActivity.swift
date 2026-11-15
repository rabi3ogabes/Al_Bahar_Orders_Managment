import ActivityKit
import SwiftUI
import WidgetKit

/// The order's Live Activity: the lock screen card and the Dynamic Island (compact, expanded and minimal).
@available(iOS 16.1, *)
struct LiveOrderLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: LiveOrderAttributes.self) { context in
            LiveOrderLockScreenView(attributes: context.attributes, state: context.state)
                .activityBackgroundTint(nil)
                .activitySystemActionForegroundColor(Color(red: 0.55, green: 0.08, blue: 0.22))
                .widgetURL(URL(string: "albahar://orders/\(context.attributes.orderId)"))
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    LiveOrderIslandLeading(attributes: context.attributes, state: context.state)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    LiveOrderIslandTrailing(attributes: context.attributes, state: context.state)
                }
                DynamicIslandExpandedRegion(.center) {
                    LiveOrderIslandCenter(attributes: context.attributes, state: context.state)
                }
                DynamicIslandExpandedRegion(.bottom) {
                    LiveOrderIslandBottom(attributes: context.attributes, state: context.state)
                }
            } compactLeading: {
                LiveOrderCompactLeading(attributes: context.attributes, state: context.state)
            } compactTrailing: {
                LiveOrderCompactTrailing(attributes: context.attributes, state: context.state)
            } minimal: {
                LiveOrderMinimal(attributes: context.attributes, state: context.state)
            }
            .keylineTint(LiveOrderIsland.palette(context.attributes, context.state).fill)
            .widgetURL(URL(string: "albahar://orders/\(context.attributes.orderId)"))
        }
    }
}
