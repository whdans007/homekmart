package net.homekmart.mall;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.ContentResolver;
import android.media.AudioAttributes;
import android.net.Uri;
import android.os.Build;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(android.os.Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            createDeliveryChannel("delivery_start", "배송 시작", R.raw.delivery_started);
            createDeliveryChannel("delivery_arrived", "배달 도착", R.raw.delivery_arrived);
            createDeliveryChannel("delivery_completed", "배송 완료", R.raw.delivery_completed);
        }
    }

    private void createDeliveryChannel(String id, String name, int soundRes) {
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null) return;
        Uri sound = Uri.parse(ContentResolver.SCHEME_ANDROID_RESOURCE + "://" + getPackageName() + "/" + soundRes);
        AudioAttributes attrs = new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_NOTIFICATION)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build();
        NotificationChannel channel = new NotificationChannel(id, name, NotificationManager.IMPORTANCE_HIGH);
        channel.setSound(sound, attrs);
        manager.createNotificationChannel(channel);
    }
}
