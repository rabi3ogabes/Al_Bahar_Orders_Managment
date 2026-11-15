import SwiftUI
import WidgetKit

/// The widget extension's entry point. The extension holds only the order's Live Activity (its deployment target is iOS 16.2).
@main
struct LiveOrderWidgetBundle: WidgetBundle {
    var body: some Widget {
        LiveOrderLiveActivity()
    }
}
