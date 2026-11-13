package qa.albahar.customer.liveorder

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.res.Configuration
import android.graphics.drawable.Icon
import android.net.Uri
import android.os.Build
import android.util.Log
import android.view.View
import android.widget.RemoteViews
import org.json.JSONObject
import qa.albahar.customer.MainActivity
import qa.albahar.customer.R
import kotlin.math.min

/**
 * One live notification per order, updated in place for the whole order: brand tile, headline ("Arrives at ..."),
 * status line and the four-stage progress bar, inside the system's decorated notification so the shade theme, header and
 * action buttons stay native. No foreground service: the card is posted when something changes and the system keeps it.
 *
 *  - ongoing while the order is active, a normal swipeable card once it is delivered, collected, cancelled or failed;
 *  - loud only the first time (heads-up), silent afterwards; a card the customer swiped away is not brought back;
 *  - two or more active orders are grouped under one summary;
 *  - times out by itself: 30 minutes after the end of the order, 12 hours as a safety net for an active one.
 */
internal object LiveOrderNotifier {
    const val CHANNEL_ID = "albahar_order_live"
    const val TAG = "albahar.live_order"
    const val EXTRA_ORDER_ID = "live_order_id"

    private const val LOG_TAG = "LiveOrder"
    private const val GROUP_KEY = "albahar_live_orders"
    private const val SUMMARY_ID = 0x4C4F0001
    private const val ACTIVE_TIMEOUT_MS = 12L * 60 * 60 * 1000
    private const val FINAL_TIMEOUT_MS = 30L * 60 * 1000
    private const val SUMMARY_TIMEOUT_MS = 6L * 60 * 60 * 1000
    private const val LOGO_DP = 56f
    private const val MAX_FONT_SCALE_FOR_COMPACT_BAR = 1.15f

    enum class Outcome(val wire: String) {
        /** Drawn or updated. */
        POSTED("posted"),

        /** A duplicate or older update: nothing to change. */
        SKIPPED("skipped"),

        /** The customer swiped this order's card away, so it stays away. */
        DISMISSED("dismissed"),

        /** Notifications are off for the app (or the channel). */
        DISABLED("disabled"),
    }

    @Synchronized
    fun show(context: Context, order: LiveOrder): Outcome {
        val app = context.applicationContext
        val store = LiveOrderStore(app)
        val now = System.currentTimeMillis()
        val nm = manager(app)
        store.purge(now).forEach { nm.cancel(TAG, notificationId(it)) }

        val prev = store.get(order.orderId)
        if (prev != null && !order.supersedes(prev.snapshot)) return Outcome.SKIPPED
        if (prev?.dismissed == true) {
            store.put(order.orderId, LiveOrderStore.Entry(order.snapshot(), true, now, 0L))
            return Outcome.DISMISSED
        }
        ensureChannel(app, nm)
        if (!LiveOrderPermissions.areEnabled(app)) return Outcome.DISABLED
        // A re-read that says what the card already says only moves the version on (so older updates stay refused).
        if (prev != null && !order.force && order.signature == prev.snapshot.signature && isShowing(nm, order.orderId)) {
            store.put(order.orderId, prev.copy(snapshot = order.snapshot(), touchedMs = now))
            return Outcome.SKIPPED
        }

        val expires = now + if (order.isFinal) FINAL_TIMEOUT_MS else ACTIVE_TIMEOUT_MS
        nm.notify(TAG, notificationId(order.orderId), build(app, order, now, expires))
        store.put(order.orderId, LiveOrderStore.Entry(order.snapshot(), false, now, expires))
        syncSummary(app, nm)
        return Outcome.POSTED
    }

    @Synchronized
    fun cancel(context: Context, orderId: String) {
        val app = context.applicationContext
        val nm = manager(app)
        nm.cancel(TAG, notificationId(orderId))
        LiveOrderStore(app).remove(orderId)
        syncSummary(app, nm)
    }

    @Synchronized
    fun cancelAll(context: Context) {
        val app = context.applicationContext
        val nm = manager(app)
        try {
            nm.activeNotifications.filter { it.tag == TAG }.forEach { nm.cancel(TAG, it.id) }
        } catch (e: Exception) {
            Log.w(LOG_TAG, "Could not list notifications", e)
        }
        nm.cancel(TAG, SUMMARY_ID)
        LiveOrderStore(app).clear()
    }

    /** The customer swiped the card away: remember it and stop bringing it back. */
    @Synchronized
    fun onDismissed(context: Context, orderId: String) {
        val app = context.applicationContext
        LiveOrderStore(app).markDismissed(orderId, System.currentTimeMillis())
        syncSummary(app, manager(app))
    }

    private fun isShowing(nm: NotificationManager, orderId: String): Boolean = try {
        val id = notificationId(orderId)
        nm.activeNotifications.any { it.tag == TAG && it.id == id }
    } catch (e: Exception) {
        Log.w(LOG_TAG, "Could not list notifications", e)
        false
    }

    private fun manager(context: Context): NotificationManager =
        context.getSystemService(NotificationManager::class.java)

    private fun notificationId(orderId: String): Int {
        val id = orderId.hashCode() and 0x7FFFFFFF
        return if (id == SUMMARY_ID) id + 1 else id
    }

    private fun ensureChannel(context: Context, nm: NotificationManager) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O || nm.getNotificationChannel(CHANNEL_ID) != null) return
        // The channel is named in the system settings, so it follows the phone's language.
        val channel = NotificationChannel(CHANNEL_ID, context.getString(R.string.live_order_channel_name), NotificationManager.IMPORTANCE_HIGH)
        channel.description = context.getString(R.string.live_order_channel_description)
        channel.setShowBadge(false)
        channel.lockscreenVisibility = Notification.VISIBILITY_PUBLIC
        nm.createNotificationChannel(channel)
    }

    @Suppress("DEPRECATION")
    private fun newBuilder(context: Context): Notification.Builder =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(context, CHANNEL_ID)
        } else {
            Notification.Builder(context).setPriority(Notification.PRIORITY_HIGH).setDefaults(Notification.DEFAULT_ALL)
        }

    private fun build(context: Context, order: LiveOrder, now: Long, expires: Long): Notification {
        val texts = LiveOrderText.forOrder(context, order)
        val rtl = texts.isRtl
        val brand = brandColor(context, order)
        val palette = LiveOrderColors.palette(brand, order.isStopped, isNight(context))
        val widthDp = barWidthDp(context)
        val headline = texts.headline(order, now)
        val subtitle = texts.subtitle(order)
        val description = texts.progressDescription(order)
        val logo = LiveOrderBarRenderer.logoTile(context, LOGO_DP)
        val id = notificationId(order.orderId)
        val open = viewIntent(context, deepLink(order), id)

        val expanded = RemoteViews(context.packageName, R.layout.live_order_expanded).apply {
            setImageViewBitmap(R.id.live_logo, logo)
            setTextViewText(R.id.live_headline, headline)
            setTextViewText(R.id.live_subtitle, subtitle)
            setImageViewBitmap(R.id.live_bar, LiveOrderBarRenderer.bar(context, order, palette, widthDp, rtl))
            setContentDescription(R.id.live_bar, description)
            val detail = texts.detail(order)
            if (detail != null) {
                setTextViewText(R.id.live_detail, detail)
                setViewVisibility(R.id.live_detail, View.VISIBLE)
            }
            if (rtl) setInt(R.id.live_root, "setLayoutDirection", View.LAYOUT_DIRECTION_RTL)
        }
        val collapsed = RemoteViews(context.packageName, R.layout.live_order_collapsed).apply {
            setImageViewBitmap(R.id.live_logo, logo)
            setTextViewText(R.id.live_headline, headline)
            setTextViewText(R.id.live_subtitle, subtitle)
            // The collapsed card is at most 48dp tall: with very large text there is no room left for the slim bar.
            if (context.resources.configuration.fontScale <= MAX_FONT_SCALE_FOR_COMPACT_BAR) {
                setImageViewBitmap(R.id.live_bar_compact, LiveOrderBarRenderer.compactBar(context, order, palette, widthDp, rtl))
                setContentDescription(R.id.live_bar_compact, description)
            } else {
                setViewVisibility(R.id.live_bar_compact, View.GONE)
            }
            if (rtl) setInt(R.id.live_root, "setLayoutDirection", View.LAYOUT_DIRECTION_RTL)
        }

        val builder = newBuilder(context)
            .setSmallIcon(R.drawable.ic_stat_notify)
            .setColor(brand)
            .setContentTitle(headline)
            .setContentText(subtitle)
            .setStyle(Notification.DecoratedCustomViewStyle())
            .setCustomContentView(collapsed)
            .setCustomBigContentView(expanded)
            .setCustomHeadsUpContentView(collapsed)
            .setCategory(if (order.isFinal) Notification.CATEGORY_STATUS else Notification.CATEGORY_PROGRESS)
            .setVisibility(Notification.VISIBILITY_PUBLIC)
            .setOnlyAlertOnce(true)
            .setOngoing(!order.isFinal)
            .setAutoCancel(order.isFinal)
            .setShowWhen(true)
            .setWhen(order.updatedMs.takeIf { it > 0 } ?: now)
            .setGroup(GROUP_KEY)
            .setContentIntent(open)
            .setDeleteIntent(dismissIntent(context, order.orderId, id))
            .addAction(action(context, texts.actionLabel(order), open))
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) builder.setTimeoutAfter(expires - now)
        countdown(builder, order, now)

        val phone = order.driverPhone?.filter { it.isDigit() || it == '+' }?.takeIf { it.length in 5..20 }
        if (order.phase == LivePhase.ON_THE_WAY && phone != null) {
            val dial = Intent(Intent.ACTION_DIAL, Uri.fromParts("tel", phone, null))
            val call = PendingIntent.getActivity(context, id, dial, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
            builder.addAction(action(context, texts.callDriverLabel(), call))
        }
        return builder.build()
    }

    /**
     * While the driver is on the road the card's header counts down to the expected arrival (mm:ss), ticking by itself
     * every second with no push and no running service. A new estimate (a push or a re-read) moves the target.
     */
    private fun countdown(builder: Notification.Builder, order: LiveOrder, now: Long) {
        if (order.phase != LivePhase.ON_THE_WAY || order.etaAtMs <= now || Build.VERSION.SDK_INT < Build.VERSION_CODES.N) return
        builder.setWhen(order.etaAtMs).setShowWhen(true).setUsesChronometer(true).setChronometerCountDown(true)
    }

    private fun action(context: Context, label: String, intent: PendingIntent): Notification.Action =
        Notification.Action.Builder(Icon.createWithResource(context, R.drawable.ic_stat_notify), label, intent).build()

    /** Opens the order in the app: the app's own deep link (`albahar://orders/{id}`), handled by the existing router. */
    private fun viewIntent(context: Context, uri: Uri, requestCode: Int): PendingIntent {
        val intent = Intent(Intent.ACTION_VIEW, uri, context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        return PendingIntent.getActivity(context, requestCode, intent, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
    }

    private fun dismissIntent(context: Context, orderId: String, requestCode: Int): PendingIntent {
        val intent = Intent(context, LiveOrderDismissReceiver::class.java).putExtra(EXTRA_ORDER_ID, orderId)
        return PendingIntent.getBroadcast(context, requestCode, intent, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
    }

    /** Only the app's own links are followed; anything else falls back to the order's page. */
    private fun deepLink(order: LiveOrder): Uri {
        val fallback = Uri.parse("albahar://orders/${Uri.encode(order.orderId)}")
        val raw = order.deepLink?.trim().orEmpty()
        if (raw.isEmpty() || raw.startsWith("//")) return fallback
        val uri = if (raw.startsWith("/")) Uri.parse("albahar:/$raw") else Uri.parse(raw)
        val own = uri.scheme == "albahar" || (uri.scheme == "https" && (uri.host == "albahar.qa" || uri.host == "www.albahar.qa"))
        return if (own) uri else fallback
    }

    /** One "N orders in progress" card above the cards of two or more active orders; none for a single order. */
    private fun syncSummary(context: Context, nm: NotificationManager) {
        val count = try {
            nm.activeNotifications.count { it.tag == TAG && it.id != SUMMARY_ID }
        } catch (e: Exception) {
            Log.w(LOG_TAG, "Could not list notifications", e)
            return
        }
        if (count < 2) {
            nm.cancel(TAG, SUMMARY_ID)
            return
        }
        val texts = LiveOrderText.forOrder(context, null)
        val brand = brandColor(context, null)
        val open = viewIntent(context, Uri.parse("albahar://orders"), SUMMARY_ID)
        val builder = newBuilder(context)
            .setSmallIcon(R.drawable.ic_stat_notify)
            .setColor(brand)
            .setContentTitle(texts.appName())
            .setContentText(texts.groupSummary(count))
            .setGroup(GROUP_KEY)
            .setGroupSummary(true)
            .setOnlyAlertOnce(true)
            .setAutoCancel(false)
            .setContentIntent(open)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            builder.setGroupAlertBehavior(Notification.GROUP_ALERT_CHILDREN).setTimeoutAfter(SUMMARY_TIMEOUT_MS)
        }
        nm.notify(TAG, SUMMARY_ID, builder.build())
    }

    /** The payload's brand colour, else the look cached from the dashboard (Flutter prefs), else the app's maroon. */
    private fun brandColor(context: Context, order: LiveOrder?): Int {
        order?.brandPrimary?.let { return it }
        try {
            val raw = LiveOrderText.flutterPref(context, "brand_theme_payload")
            val hex = raw?.let { JSONObject(it).optJSONObject("colors")?.optString("primary") }
            LiveOrderColors.parseHex(hex)?.let { return it }
        } catch (_: Exception) {
            // Unreadable cache: use the default below.
        }
        return context.getColor(R.color.notification_accent)
    }

    private fun isNight(context: Context): Boolean =
        context.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK == Configuration.UI_MODE_NIGHT_YES

    /** Width the bar is drawn for: the narrow side of the screen minus the card's padding, so it scales down, not up. */
    private fun barWidthDp(context: Context): Float {
        val dm = context.resources.displayMetrics
        val narrowDp = min(dm.widthPixels, dm.heightPixels) / dm.density
        return (narrowDp - 72f).coerceIn(240f, 420f)
    }
}
