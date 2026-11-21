# PayRelay R8 / ProGuard Configuration

# Retain generic signatures, annotations, and enclosing method metadata
# required by Retrofit, kotlinx.serialization, Hilt, and Room.
-keepattributes *Annotation*,Signature,InnerClasses,EnclosingMethod,Exceptions

# Strip all android.util.Log calls in release builds (Security Rule #7)
-assumenosideeffects class android.util.Log {
    public static boolean isLoggable(java.lang.String, int);
    public static int v(...);
    public static int d(...);
    public static int i(...);
    public static int w(...);
    public static int e(...);
    public static int wtf(...);
}

# kotlinx.serialization
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.SerializationKt
-keep,includedescriptorclasses class com.alsaeeddev.payrelay.data.remote.dto.**$$serializer { *; }
-keep,includedescriptorclasses class com.alsaeeddev.payrelay.ui.navigation.**$$serializer { *; }
-keepclassmembers class com.alsaeeddev.payrelay.data.remote.dto.** {
    *** Companion;
}
-keepclassmembers class com.alsaeeddev.payrelay.ui.navigation.** {
    *** Companion;
}
-keepclasseswithmembers class com.alsaeeddev.payrelay.data.remote.dto.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keepclasseswithmembers class com.alsaeeddev.payrelay.ui.navigation.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keep @kotlinx.serialization.Serializable class com.alsaeeddev.payrelay.** { *; }

# Retrofit interfaces and DTOs
-keep,allowobfuscation,allowshrinking interface com.alsaeeddev.payrelay.data.remote.MpavApi { *; }
-keepclassmembers,allowshrinking,allowobfuscation interface * {
    @retrofit2.http.* <methods>;
}

# Room entities, DAOs, and Database
-keep class * extends androidx.room.RoomDatabase
-keep @androidx.room.Entity class com.alsaeeddev.payrelay.data.local.db.** { *; }
-keep @androidx.room.Dao interface com.alsaeeddev.payrelay.data.local.db.** { *; }

# Manifest-registered components & Hilt-injected WorkManager Workers
-keep class com.alsaeeddev.payrelay.PayRelayApp { *; }
-keep class com.alsaeeddev.payrelay.MainActivity { *; }
-keep class com.alsaeeddev.payrelay.receiver.** { *; }
-keep class com.alsaeeddev.payrelay.worker.** { *; }

# OkHttp / optional platform TLS providers
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn javax.annotation.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# ZXing QR decoding
-keep class com.google.zxing.** { *; }

-keep class com.google.mlkit.** { *; }
-keep class com.google.android.gms.internal.mlkit_vision_barcode.** { *; }
-dontwarn com.google.mlkit.**