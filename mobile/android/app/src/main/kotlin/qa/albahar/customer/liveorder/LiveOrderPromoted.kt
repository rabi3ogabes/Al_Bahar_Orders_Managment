package qa.albahar.customer.liveorder

import android.annotation.TargetApi
import android.app.Notification
import android.content.Context
import android.graphics.drawable.Icon
import android.os.Bundle
import android.util.Log
import kotlin.math.roundToInt

/**
 * Android 16 (API 36) "Live Updates": the order as a promoted ongoing notification, drawn by the system itself.
 *
 * A promoted notification may not use custom views, so on API 36 the card is described to the platform instead of
 * drawn: the three segments of the progress style are the three legs of the order (placed to preparing, preparing to on
 * the way, on the way to delivered), the tracker icon is the stage the order is at, and the system shows the status-bar
 * chip (the countdown to the driver's arrival, else the arrival time) and the rich lock-screen presentation. The same
 * title, status line, actions, group and timeouts as the custom card are set by [LiveOrderNotifier]; this only adds what
 * is specific to the platform style.
 *
 * Everything here is guarded by `Build.VERSION.SDK_INT >= MIN_SDK` at the call site and by a try/catch: whatever the
 * platform refuses, [apply] answers false and the notifier draws the custom card instead. A customer who has switched
 * "Live updates" off for the app still gets the platform progress notification, just not the chip.
 */
@TargetApi(36)
internal object LiveOrderPromoted {
    /** Android 16. */
    const val MIN_SDK = 36

    private const val LOG_TAG = "LiveOrder"

    /** `Notification.EXTRA_REQUEST_PROMOTED_ONGOING`. */
    private const val EXTRA_REQUEST_PROMOTED_ONGOING = "android.requestPromotedOngoing"

    /** Each leg of the bar is 100 units long, so a progress of 150 is half way through the second leg. */
    private const val LEG = 100
    private const val BADGE_DP = 24f

    /**
     * Describes the order with the platform's progress style and asks for promotion while it is active.
     * @return false when this could not be done (the caller then draws the custom card)
     */
    fun apply(
        context: Context,
        builder: Notification.Builder,
        order: LiveOrder,
        texts: LiveOrderText,
        palette: LiveOrderColors.Palette,
        rtl: Boolean,
        now: Long,
    ): Boolean = try {
        val segments = (0 until LiveOrder.STAGES - 1).map {
            Notification.ProgressStyle.Segment(LEG).setColor(palette.fill)
        }
        val badge = LiveOrderBarRenderer.stageBadge(context, order, palette, BADGE_DP, rtl)
        val style = Notification.ProgressStyle()
            .setStyledByProgress(true)
            .setProgressSegments(segments)
            .setProgress(progress(order))
            .setProgressTrackerIcon(Icon.createWithBitmap(badge))
        builder.setStyle(style)
        if (!order.isFinal) {
            // The chip counts down to the arrival while the driver is on the road (the notifier set the chronometer),
            // otherwise it shows the arrival time. A finished order is a plain, dismissable notification.
            chipText(order, texts, now)?.let { builder.setShortCriticalText(it) }
            // The builder method of the same name is not in every API 36 SDK, so the request is made the way the platform reads it: the extra.
            builder.addExtras(Bundle().apply { putBoolean(EXTRA_REQUEST_PROMOTED_ONGOING, true) })
        }
        true
    } catch (t: Throwable) {
        Log.w(LOG_TAG, "Live update style not available, drawing the card instead", t)
        false
    }

    /** Where the tracker sits on the bar: whole legs for the stages reached, plus the share of the current leg. */
    fun progress(order: LiveOrder): Int {
        val done = order.segmentFill().sum()
        return (done * LEG).roundToInt().coerceIn(0, (LiveOrder.STAGES - 1) * LEG)
    }

    /** Short text for the status-bar chip (at most about 7 characters): the arrival time; null when there is none or a countdown shows. */
    fun chipText(order: LiveOrder, texts: LiveOrderText, now: Long): String? {
        if (order.phase == LivePhase.ON_THE_WAY && order.etaAtMs > now) return null
        val at = if (order.etaToMs > 0) order.etaToMs else order.etaFromMs
        return if (at > 0) texts.time(at) else null
    }
}
