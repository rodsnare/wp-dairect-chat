=== dAIrect Chat ===
Contributors: rodsnare  
Tags: ai, chatbot, gemini, widget, llm  
Requires at least: 6.0  
Tested up to: 6.8  
Stable tag: 1.1.2  
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

== Frequently Asked Questions ==

= Does this store user messages? =
No. Session memory is stored only in PHP sessions — no logs are stored in WordPress or external databases.

= Can I use OpenAI or Claude instead? =
Not yet, but future versions will support multiple providers.

== Screenshots ==

1. Admin panel with system instruction, sliders, and prompt tester
2. Front-end widget with configurable behavior and voice button

== Changelog ==

= 1.1.2 =
* First public GitHub release
* System instruction injection and admin tester support
* Stable shortcode and styling foundation

== Upgrade Notice ==

1.1.2 introduces session memory, shortcode embed, and admin config — recommended for all users.

== License ==

License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


MIT — Fork freely, build professionally.