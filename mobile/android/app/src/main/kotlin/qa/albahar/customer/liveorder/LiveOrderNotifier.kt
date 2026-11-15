package qa.albahar.customer.liveorder

import android.app.AlarmManager
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
 * One live notification per order, updated in place for the whole order: headline ("Arrives at ..."), status line, the
 * four-stage progress bar and, once a driver is on the job, a driver chip, inside the system's decorated notification so
 * the shade theme, the header (the one brand mark: small icon and app name) and the action buttons stay native. On Android
 * 16 (API 36) the platform's own progress style is used instead, promoted to a status-bar chip and a rich lock-screen card
 * ([LiveOrderPromoted]); older Android keeps the custom card. No foreground service: the card is posted when something
 * changes and the system keeps it.
 *
 *  - ongoing while the order is active, a normal swipeable card once it is delivered, collected, cancelled or failed;
 *  - on the lock screen: the full card when the phone shows everything, else a public version with only the stage and the
 *    arrival time (no driver, no phone number, no address: the card never carries an address);
 *  - alerts (heads-up, sound per the channel) when the card first appears and when the order moves to another stage,
 *    silent for everything else (bar movement, a shifted window, the countdown); a card the customer swiped away is not
 *    brought back;
 *  - two or more active orders are grouped under one summary;
 *  - times out by itself: 30 minutes after the end of the order, 12 hours as a safety net for an active one; the countdown
 *    in the header is replaced by the plain time as soon as it reaches zero, so it never counts below zero.
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
    private const val MAX_FONT_SCALE_FOR_COMPACT_BAR = 1.15f

    /** The countdown is redrawn this long after it reaches zero (an inexact alarm may fire a little later still). */
    private const val COUNTDOWN_GRACE_MS = 1_500L
    private const val COUNTDOWN_ACTION = "qa.albahar.customer.LIVE_ORDER_COUNTDOWN"

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
            store.put(order.orderId, prev.copy(snapshot = order.snapshot(), touchedMs = now, payload = LiveOrderPayload.encode(order)))
            return Outcome.SKIPPED
        }

        // Alert when the card appears and when the order moves to another stage; everything else changes quietly.
        val alert = prev == null || prev.snapshot.phase != order.phase || !isShowing(nm, order.orderId)
        val expires = now + if (order.isFinal) FINAL_TIMEOUT_MS else ACTIVE_TIMEOUT_MS
        nm.notify(TAG, notificationId(order.orderId), build(app, order, now, expires, alert))
        store.put(order.orderId, LiveOrderStore.Entry(order.snapshot(), false, now, expires, LiveOrderPayload.encode(order)))
        scheduleCountdownEnd(app, order, now)
        syncSummary(app, nm)
        return Outcome.POSTED
    }

    @Synchronized
    fun cancel(context: Context, orderId: String) {
        val app = context.applicationContext
        val nm = manager(app)
        nm.cancel(TAG, notificationId(orderId))
        cancelCountdownEnd(app, orderId)
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
        val store = LiveOrderStore(app)
        store.ids().forEach { cancelCountdownEnd(app, it) }
        store.clear()
    }

    /** The customer swiped the card away: remember it and stop bringing it back. */
    @Synchronized
    fun onDismissed(context: Context, orderId: String) {
        val app = context.applicationContext
        LiveOrderStore(app).markDismissed(orderId, System.currentTimeMillis())
        cancelCountdownEnd(app, orderId)
        syncSummary(app, manager(app))
    }

    /**
     * The driver's expected arrival has passed with no newer estimate: draw the card again without the countdown, so the
     * header shows the plain time instead of a timer running below zero. Silent. Nothing to do when the card is gone,
     * swiped away, finished, or has been given a later estimate in the meantime.
     */
    @Synchronized
    fun onCountdownEnd(context: Context, orderId: String) {
        val app = context.applicationContext
        val store = LiveOrderStore(app)
        val entry = store.get(orderId) ?: return
        if (entry.dismissed) return
        val order = LiveOrderPayload.decode(entry.payload) ?: return
        val now = System.currentTimeMillis()
        if (order.isFinal || order.etaAtMs <= 0L || order.etaAtMs > now) return
        val nm = manager(app)
        if (!isShowing(nm, orderId)) return
        val calm = order.copy(etaAtMs = 0L)
        val expires = entry.expiresMs.takeIf { it > now } ?: (now + ACTIVE_TIMEOUT_MS)
        nm.notify(TAG, notificationId(orderId), build(app, calm, now, expires, alert = false))
        store.put(orderId, entry.copy(payload = LiveOrderPayload.encode(calm)))
    }

    private fun scheduleCountdownEnd(context: Context, order: LiveOrder, now: Long) {
        try {
            val alarms = context.getSystemService(AlarmManager::class.java) ?: return
            val pending = countdownIntent(context, order.orderId)
            if (order.phase == LivePhase.ON_THE_WAY && !order.isFinal && order.etaAtMs > now) {
                // Not exact and not waking: a second or two of delay is fine, and no special permission is needed.
                alarms.set(AlarmManager.RTC, order.etaAtMs + COUNTDOWN_GRACE_MS, pending)
            } else {
                alarms.cancel(pending)
            }
        } catch (e: Exception) {
            Log.w(LOG_TAG, "Could not schedule the end of the countdown", e)
        }
    }

    private fun cancelCountdownEnd(context: Context, orderId: String) {
        try {
            context.getSystemService(AlarmManager::class.java)?.cancel(countdownIntent(context, orderId))
        } catch (e: Exception) {
            Log.w(LOG_TAG, "Could not cancel the end of the countdown", e)
        }
    }

    private fun countdownIntent(context: Context, orderId: String): PendingIntent {
        val intent = Intent(context, LiveOrderCountdownReceiver::class.java)
            .setAction(COUNTDOWN_ACTION)
            .putExtra(EXTRA_ORDER_ID, orderId)
        return PendingIntent.getBroadcast(context, notificationId(orderId), intent, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
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

    /**
     * The notification for [order]. [alert] lets this post make a sound / heads-up (the first card and a new stage);
     * every other update is silent.
     */
    private fun build(context: Context, order: LiveOrder, now: Long, expires: Long, alert: Boolean): Notification {
        val texts = LiveOrderText.forOrder(context, order)
        val rtl = texts.isRtl
        val brand = brandColor(context, order)
        val palette = LiveOrderColors.palette(brand, order.isStopped, isNight(context))
        val headline = texts.headline(order, now)
        val subtitle = texts.subtitle(order)
        val id = notificationId(order.orderId)
        val open = viewIntent(context, deepLink(order), id)

        val builder = newBuilder(context)
            .setSmallIcon(R.drawable.ic_stat_notify) // the one brand mark: the system header draws it (tinted) next to the app name
            .setColor(brand)
            .setContentTitle(headline)
            .setContentText(subtitle)
            .setCategory(if (order.isFinal) Notification.CATEGORY_STATUS else Notification.CATEGORY_PROGRESS)
            .setOnlyAlertOnce(!alert)
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

        // Lock screen. A finished order has nothing private in it. An active one is "private": a phone that hides
        // sensitive content shows the public version (stage and arrival time only) instead of the driver and the status
        // line, which can name him.
        if (order.isFinal) {
            builder.setVisibility(Notification.VISIBILITY_PUBLIC)
        } else {
            builder.setVisibility(Notification.VISIBILITY_PRIVATE).setPublicVersion(publicVersion(context, order, texts, palette, brand, headline))
        }

        val phone = order.driverPhone?.filter { it.isDigit() || it == '+' }?.takeIf { it.length in 5..20 }
        if (!order.isFinal && phone != null) {
            val dial = Intent(Intent.ACTION_DIAL, Uri.fromParts("tel", phone, null))
            val call = PendingIntent.getActivity(context, id, dial, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
            builder.addAction(action(context, texts.callDriverLabel(), call))
        }

        // Android 16: the platform's progress style, promoted by the system. Anything it cannot do falls back to the card.
        val promoted = Build.VERSION.SDK_INT >= LiveOrderPromoted.MIN_SDK &&
            LiveOrderPromoted.apply(context, builder, order, texts, palette, rtl, now)
        if (!promoted) applyCard(context, builder, order, texts, palette, rtl, headline, subtitle)
        return builder.build()
    }

    /** The custom card (Android 7 to 15): collapsed, heads-up and expanded content inside the decorated notification. */
    private fun applyCard(
        context: Context,
        builder: Notification.Builder,
        order: LiveOrder,
        texts: LiveOrderText,
        palette: LiveOrderColors.Palette,
        rtl: Boolean,
        headline: String,
        subtitle: String,
    ) {
        val widthDp = barWidthDp(context)
        val description = texts.progressDescription(order)
        // The bars are drawn left to right or right to left, so the layout must follow the app's language, not the phone's.
        val direction = if (rtl) View.LAYOUT_DIRECTION_RTL else View.LAYOUT_DIRECTION_LTR

        val expanded = RemoteViews(context.packageName, R.layout.live_order_expanded).apply {
            setTextViewText(R.id.live_headline, headline)
            setTextViewText(R.id.live_subtitle, subtitle)
            setImageViewBitmap(R.id.live_bar, LiveOrderBarRenderer.bar(context, order, palette, widthDp, rtl))
            setContentDescription(R.id.live_bar, description)
            val driver = texts.detail(order)
            if (driver != null) {
                setTextViewText(R.id.live_driver, driver)
                setViewVisibility(R.id.live_driver_chip, View.VISIBLE)
            }
            setInt(R.id.live_root, "setLayoutDirection", direction)
        }
        val collapsed = collapsedView(context, order, palette, headline, subtitle, rtl, widthDp, description)

        builder
            .setStyle(Notification.DecoratedCustomViewStyle())
            .setCustomContentView(collapsed)
            .setCustomBigContentView(expanded)
            .setCustomHeadsUpContentView(collapsed)
    }

    private fun collapsedView(
        context: Context,
        order: LiveOrder,
        palette: LiveOrderColors.Palette,
        headline: String,
        subtitle: String,
        rtl: Boolean,
        widthDp: Float,
        description: String,
    ): RemoteViews = RemoteViews(context.packageName, R.layout.live_order_collapsed).apply {
        setTextViewText(R.id.live_headline, headline)
        setTextViewText(R.id.live_subtitle, subtitle)
        // The collapsed card is at most 48 dp tall: with very large text there is no room left for the slim bar.
        if (context.resources.configuration.fontScale <= MAX_FONT_SCALE_FOR_COMPACT_BAR) {
            setImageViewBitmap(R.id.live_bar_compact, LiveOrderBarRenderer.compactBar(context, order, palette, widthDp, rtl))
            setContentDescription(R.id.live_bar_compact, description)
        } else {
            setViewVisibility(R.id.live_bar_compact, View.GONE)
        }
        setInt(R.id.live_root, "setLayoutDirection", if (rtl) View.LAYOUT_DIRECTION_RTL else View.LAYOUT_DIRECTION_LTR)
    }

    /** What a locked phone shows when it hides sensitive content: the arrival time, the stage and the bar, nothing more. */
    private fun publicVersion(
        context: Context,
        order: LiveOrder,
        texts: LiveOrderText,
        palette: LiveOrderColors.Palette,
        brand: Int,
        headline: String,
    ): Notification {
        val subtitle = texts.publicSubtitle(order)
        val collapsed = collapsedView(context, order, palette, headline, subtitle, texts.isRtl, barWidthDp(context), texts.progressDescription(order))
        return newBuilder(context)
            .setSmallIcon(R.drawable.ic_stat_notify)
            .setColor(brand)
            .setContentTitle(headline)
            .setContentText(subtitle)
            .setStyle(Notification.DecoratedCustomViewStyle())
            .setCustomContentView(collapsed)
            .setVisibility(Notification.VISIBILITY_PUBLIC)
            .setShowWhen(true)
            .setWhen(order.updatedMs.takeIf { it > 0 } ?: System.currentTimeMillis())
            .build()
    }

    /**
     * While the driver is on the road the card's header counts down to the expected arrival (mm:ss), ticking by itself
     * every second with no push and no running service. A new estimate (a push or a re-read) moves the target; when the
     * target is reached [onCountdownEnd] draws the card again without it.
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
