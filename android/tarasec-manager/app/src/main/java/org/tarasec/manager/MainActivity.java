package org.tarasec.manager;

import android.app.Activity;
import android.os.Bundle;
import android.text.InputType;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.CookieHandler;
import java.net.CookieManager;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class MainActivity extends Activity {
    private final ExecutorService executor = Executors.newSingleThreadExecutor();
    private EditText gatewayUrl;
    private EditText managerKey;
    private TextView status;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        CookieHandler.setDefault(new CookieManager());

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(32, 32, 32, 32);

        TextView title = new TextView(this);
        title.setText("TaraSec Gateway Manager");
        title.setTextSize(24);
        root.addView(title);

        gatewayUrl = new EditText(this);
        gatewayUrl.setHint("Gateway URL, e.g. http://10.100.1.1");
        gatewayUrl.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        root.addView(gatewayUrl);

        managerKey = new EditText(this);
        managerKey.setHint("Manager key");
        managerKey.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        root.addView(managerKey);

        Button login = new Button(this);
        login.setText("Login");
        login.setOnClickListener(v -> login());
        root.addView(login);

        Button refresh = new Button(this);
        refresh.setText("Refresh gateway status");
        refresh.setOnClickListener(v -> refreshStatus());
        root.addView(refresh);

        Button logout = new Button(this);
        logout.setText("Logout");
        logout.setOnClickListener(v -> logout());
        root.addView(logout);

        status = new TextView(this);
        status.setText("Not connected.");
        status.setTextSize(16);
        status.setPadding(0, 24, 0, 0);
        root.addView(status);

        ScrollView scroll = new ScrollView(this);
        scroll.addView(root);
        setContentView(scroll);
    }

    private String baseUrl() {
        String value = gatewayUrl.getText().toString().trim();
        while (value.endsWith("/")) value = value.substring(0, value.length() - 1);
        return value;
    }

    private void login() {
        String base = baseUrl();
        String key = managerKey.getText().toString().trim();
        if (base.isEmpty() || key.isEmpty()) {
            show("Gateway URL and manager key are required.");
            return;
        }
        show("Logging in…");
        executor.execute(() -> {
            try {
                String form = "action=login&key=" + URLEncoder.encode(key, StandardCharsets.UTF_8.name());
                JSONObject reply = request("POST", base + "/script/managerAuth.php", form);
                if (!reply.optBoolean("ok")) {
                    show("Login failed: " + reply.optString("error", "unknown error"));
                    return;
                }
                runOnUiThread(() -> managerKey.setText(""));
                refreshStatus();
            } catch (Exception e) {
                show("Login failed: " + e.getMessage());
            }
        });
    }

    private void refreshStatus() {
        String base = baseUrl();
        if (base.isEmpty()) {
            show("Gateway URL is required.");
            return;
        }
        show("Refreshing gateway…");
        executor.execute(() -> {
            try {
                JSONObject reply = request("GET", base + "/script/managerGateway.php?action=status", null);
                if (!reply.optBoolean("ok")) {
                    show("Status failed: " + reply.optString("error", "unknown error"));
                    return;
                }
                JSONObject gateway = reply.getJSONObject("gateway");
                JSONObject manager = reply.getJSONObject("manager");
                JSONObject capabilities = reply.getJSONObject("capabilities");
                String text = "Gateway: " + gateway.optString("name", "TaraSec gateway")
                        + "\nReachable: " + gateway.optBoolean("reachable")
                        + "\nServer time: " + gateway.optString("serverTime")
                        + "\nManager: " + manager.optString("email")
                        + "\n\nCapabilities"
                        + "\nStatus: " + capabilities.optBoolean("status")
                        + "\nThreats: " + capabilities.optBoolean("threats")
                        + "\nUnits: " + capabilities.optBoolean("units")
                        + "\nNotifications: " + capabilities.optBoolean("notifications");
                show(text);
            } catch (Exception e) {
                show("Status failed: " + e.getMessage());
            }
        });
    }

    private void logout() {
        String base = baseUrl();
        if (base.isEmpty()) {
            show("Gateway URL is required.");
            return;
        }
        executor.execute(() -> {
            try {
                request("POST", base + "/script/managerAuth.php", "action=logout");
                show("Logged out.");
            } catch (Exception e) {
                show("Logout failed: " + e.getMessage());
            }
        });
    }

    private JSONObject request(String method, String target, String form) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(target).openConnection();
        conn.setConnectTimeout(10000);
        conn.setReadTimeout(10000);
        conn.setRequestMethod(method);
        conn.setRequestProperty("Accept", "application/json");
        if (form != null) {
            byte[] body = form.getBytes(StandardCharsets.UTF_8);
            conn.setDoOutput(true);
            conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");
            conn.setFixedLengthStreamingMode(body.length);
            try (OutputStream out = conn.getOutputStream()) {
                out.write(body);
            }
        }

        int code = conn.getResponseCode();
        InputStream in = code >= 400 ? conn.getErrorStream() : conn.getInputStream();
        StringBuilder text = new StringBuilder();
        if (in != null) {
            try (BufferedReader reader = new BufferedReader(new InputStreamReader(in, StandardCharsets.UTF_8))) {
                String line;
                while ((line = reader.readLine()) != null) text.append(line);
            }
        }
        conn.disconnect();
        if (text.length() == 0) throw new IllegalStateException("HTTP " + code + " with empty response");
        return new JSONObject(text.toString());
    }

    private void show(String text) {
        runOnUiThread(() -> status.setText(text));
    }

    @Override
    protected void onDestroy() {
        executor.shutdownNow();
        super.onDestroy();
    }
}
