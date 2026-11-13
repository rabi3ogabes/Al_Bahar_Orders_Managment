package qa.albahar.customer.liveorder

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Draws `order_progress` push messages natively, with the app in the foreground, in the background or closed.
 *
 * firebase_messaging hands a push to a Dart background isolate when the app is not on screen, and an isolate started
 * that way has no access to the app's own method channel. This receiver listens for the same broadcast (Firebase
 * sends it to every receiver registered for it, next to FlutterFirebaseMessagingReceiver) and renders the card itself,
 * in a few milliseconds and without starting Flutter. The Dart side ignores the same message in the background; when
 * the app is on screen it also gets it, and the notifier drops that duplicate by its sequence number.
 */
class LiveOrderFcmReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != ACTION_RECEIVE) return
        val extras = intent.extras ?: return
        if (extras.getString("kind") != "order_progress") return
        try {
            // Data messages put their key/value pairs straight into the extras (all strings).
            val data = HashMap<String, Any?>()
            for (key in extras.keySet()) extras.getString(key)?.let { data[key] = it }
            val order = LiveOrder.fromMap(data) ?: return
            LiveOrderNotifier.show(context, order)
        } catch (t: Throwable) {
            Log.w(LOG_TAG, "Could not draw the order notification", t)
        }
    }

    private companion object {
        const val ACTION_RECEIVE = "com.google.android.c2dm.intent.RECEIVE"
        const val LOG_TAG = "LiveOrder"
    }
}

/** The customer swiped an order card away: remember it, so later updates do not bring it back. */
class LiveOrderDismissReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val orderId = intent.getStringExtra(LiveOrderNotifier.EXTRA_ORDER_ID) ?: return
        LiveOrderNotifier.onDismissed(context, orderId)
    }
}
