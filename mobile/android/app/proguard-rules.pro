# Flutter / plugins keep rules (R8 full mode).
-keep class io.flutter.** { *; }
-keep class com.dexterous.** { *; }
-keep class com.google.firebase.** { *; }
-dontwarn com.google.android.play.core.**

# Scan to add (mobile_scanner): the camera (CameraX) and the barcode reader (ML Kit) are reached through generated glue and native code,
# so release builds (R8: minify + shrink resources) must keep them, or the camera never opens in the shipped app although debug works.
-keep class androidx.camera.** { *; }
-keep class com.google.mlkit.** { *; }
-dontwarn com.google.mlkit.**
-keep class dev.steenbakker.mobile_scanner.** { *; }
