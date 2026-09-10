package net.homekmart.driver;

import android.os.Bundle;
import android.view.View;
import androidx.activity.OnBackPressedCallback;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;
import com.getcapacitor.BridgeActivity;

/**
 * 서버 렌더링 페이지(비-SPA)를 로드하므로 로컬 www 자산에 JS 백버튼 핸들러를 둘 수 없다.
 * WebView 히스토리가 있으면 뒤로 이동하고, 최상단(로그인/목록 첫 화면)에서는 앱을 종료하지
 * 않고 홈으로 내려보내 백그라운드 유지 — 기사가 배달 중 실수로 앱을 꺼뜨리지 않도록 한다.
 */
public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        getWindow().setStatusBarColor(android.graphics.Color.WHITE);
        getWindow().setNavigationBarColor(android.graphics.Color.WHITE);
        WindowInsetsControllerCompat controller = new WindowInsetsControllerCompat(getWindow(), getWindow().getDecorView());
        controller.setAppearanceLightStatusBars(true);
        controller.setAppearanceLightNavigationBars(true);

        // Android 15+ forces edge-to-edge for apps targeting recent SDKs.  Insets are
        // dispatched to the activity content root (not reliably to Capacitor's WebView),
        // so constrain the entire WebView host to the usable area of the screen.
        View content = findViewById(android.R.id.content);
        ViewCompat.setOnApplyWindowInsetsListener(content, (view, windowInsets) -> {
            Insets bars = windowInsets.getInsets(
                WindowInsetsCompat.Type.statusBars()
                    | WindowInsetsCompat.Type.navigationBars()
                    | WindowInsetsCompat.Type.displayCutout()
            );
            // The web pages already apply env(safe-area-inset-bottom) to their
            // fixed bottom navigation. Applying the native bottom inset here as
            // well would create a large double gap below that navigation.
            view.setPadding(bars.left, bars.top, bars.right, 0);
            return windowInsets;
        });
        ViewCompat.requestApplyInsets(content);

        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                if (getBridge() != null && getBridge().getWebView().canGoBack()) {
                    getBridge().getWebView().goBack();
                } else {
                    moveTaskToBack(true);
                }
            }
        });
    }
}
