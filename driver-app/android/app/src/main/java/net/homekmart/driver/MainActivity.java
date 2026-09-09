package net.homekmart.driver;

import android.os.Bundle;
import androidx.activity.OnBackPressedCallback;
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
