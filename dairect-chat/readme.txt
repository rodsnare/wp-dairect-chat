=== dAIrect Chat ===
Contributors: rsnare  
Tags: ai, chatbot, gemini, widget, llm  
Requires at least: 6.0  
Tested up to: 6.8  
Stable tag: 2.0.30  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

dAIrect Chat is a lightweight Gemini AI chat widget for WordPress with multi-turn memory, voice input, and custom system instructions.

== Description ==
dAIrect Chat enables you to embed a Gemini-powered chatbot into any page or post using a shortcode. Designed for simplicity, flexibility, and fast response, it is perfect for customer support, creative interaction, or building your own branded assistant.

= Features include: =
* Multi-turn memory (stored per user)
* Configurable: temperature, topP, max tokens, timeout
* Custom system instruction (acts as a personality or role)
* Voice input (Chrome only)
* Easy shortcode integration: `[dairect_chat_widget]`
* Admin test prompt with saved parameter preview

== Installation ==
1. Upload the `dairect-chat` folder to your `/wp-content/plugins/` directory or install via ZIP
2. Activate the plugin through the WordPress admin
3. Add your Gemini API key to your `wp-config.php` file:  
   `putenv('GEMINI_API_KEY=your-api-key-here');`
4. Visit **Tools > dAIrect Chat** to configure settings
5. Add `[dairect_chat_widget]` to any page or post

== External Services ==
This plugin connects to the Google Gemini API to generate conversational responses using AI.

- Data sent: Chat message contents and optional system instructions.
- Sent when: The user sends a message via the widget or admin test prompt.
- Service provider: Google LLC  
  [Terms of Service](https://policies.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)

== Frequently Asked Questions ==
= Does this store user messages? =  
No. Session memory is stored only in PHP sessions — no logs are stored in WordPress or external databases.

= Can I use OpenAI or Claude instead? =  
Not yet, but future versions will support multiple providers.

== Screenshots ==
1. Admin panel with system instruction, sliders, and prompt tester
2. Front-end widget with configurable behavior and voice button

== Changelog ==
= 2.0.30 =
* Fix: use User-Agent: python-requests/2.31.0 for all Phoenix OTLP requests — Phoenix Cloud's GFE routes to its OTLP API handler only for python-requests/* user agents; any other UA is routed to the web app and redirected to login (HTTP 302)

= 2.0.29 =
* Fix: Phoenix OTLP traces now reach Phoenix Cloud — root cause was missing User-Agent header; GFE (Google's proxy) silently rejected all PHP cURL requests without one; add User-Agent, Accept-Encoding, and switch live sender to HTTP/1.1 (confirmed working via CLI diagnostic)

= 2.0.28 =
* Fix: revert label suppression rule — theme uses the label element as the visible styled form control, hiding it removed the input entirely

= 2.0.27 =
* Fix: input field narrows on sites whose theme overrides width/display on generic input and wrapper selectors — added !important guards to keep the chat input full-width regardless of external CSS
* Fix: suppress theme-injected floating labels (auto-generated from placeholder attribute) inside the chat drawer input area

= 2.0.26 =
* Fix: apply same guest nonce bypass to Clear Chat action (same caching root cause as send message)

= 2.0.25 =
* Fix: skip nonce check for logged-out users to prevent caching plugins from breaking guest chat with stale logged-in nonces (Error: -1)

= 2.0.24 =
* Debug: fix cURL verbose capture — use tempnam(sys_get_temp_dir()) instead of tmpfile() which fails in PHP-FPM; add Connection: keep-alive to verbose test headers to match Python requests behavior

= 2.0.23 =
* Fix: remove CURLOPT_POSTFIELDSIZE which is not exported in all PHP cURL builds (caused fatal on PHP 8.0 with certain libcurl versions)

= 2.0.22 =
* Debug: wrap AJAX handler in try/catch Throwable so PHP fatals return JSON instead of HTML error page; add PHP version to diagnostic steps

= 2.0.21 =
* Debug: fix verbose cURL output (HTML-escape so TLS cipher names show), use CURLOPT_HEADER for response headers, add server outbound IP diagnostic

= 2.0.20 =
* Debug: add verbose cURL diagnostic variant (CUSTOMREQUEST + Python-like headers + full trace + connected IP) to isolate Phoenix 400 root cause

= 2.0.19 =
* Debug: add CURLOPT_CUSTOMREQUEST and wp_remote_post test variants to Phoenix test button to isolate cURL 400 root cause

= 2.0.18 =
* Fix: register wp_ajax_nopriv_dairect_chat so logged-out users can send chat messages

= 2.0.17 =
* Add unconditional file_put_contents diagnostic at top of dairect_phoenix_send() to confirm call path and show phoenix_enabled / api_key_len regardless of WP_DEBUG

= 2.0.16 =
* Fix Phoenix live sender diagnostic logging — use file_put_contents to bypass WP error handler shutdown limitation
* Add synchronous pre-shutdown log entry to confirm dairect_phoenix_send() is being called

= 2.0.15 =
* Phoenix test button adds OTLP/JSON variant to diagnose protobuf encoder vs endpoint issues
* Phoenix test button now shows response body even when empty, adds HTTP/1.1 variant to rule out HTTP/2 issues
* Live sender diagnostic logging added to register_shutdown_function

= 2.0.1 =
* Floating drawer with animated open/close and button drop/spring return
* Configurable drawer title, accent color picker, and Phoenix collector endpoint
* Microphone button moved inside input field with improved toggle and error handling
* Prompt tracing with Phoenix Cloud OTLP observability
* System instructions focus warning modal

= 1.1.3 =
* First public GitHub release
* System instruction injection and admin tester support
* Stable shortcode and styling foundation

== Upgrade Notice ==
1.1.3 introduces session memory, shortcode embed, and admin config — recommended for all users.

== License ==
GPLv2 or later — Fork freely, build professionally.

License URI: https://www.gnu.org/licenses/gpl-2.0.html


MIT — Fork freely, build professionally.