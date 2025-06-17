# dAIrect Chat

**Version:** 1.1.3  
**License:** MIT/GPLv2  
**Author:** Rod Snare  
**Tags:** WordPress, Chatbot, Gemini, AI, Widget, Plugin

dAIrect Chat is a lightweight, configurable multi-turn conversational AI plugin for WordPress powered by Google Gemini. It features an embeddable chat widget, admin-configurable behavior, and support for system instructions, voice input, and advanced generation parameters.

## 🚀 Features

- Multi-turn session memory (stored per user)
- Configurable `temperature`, `topP`, `maxTokens`, `timeout`
- Custom `system_instruction` injection
- Voice input (Chrome only)
- Shortcode: `[dairect_chat_widget]`
- Admin test prompt with full parameter reflection

## 🛠 Installation

1. Upload the plugin to your WordPress `wp-content/plugins` directory
2. Add this line to your `wp-config.php` (replace with your real API key):
   ```php
   putenv('GEMINI_API_KEY=your-api-key');
   ```
3. Activate the plugin in the admin dashboard
4. Go to `Tools > dAIrect Chat` to configure model settings
5. Add `[dairect_chat_widget]` to any post or page

## 📂 Plugin Structure

```
dairect-chat/
├── dairect-chat.php
├── admin/
├── includes/
└── public/
```

## 📦 License

MIT License. Fork it, use it, build a pro version on it. You earned it.

---

Tested with WordPress 6.5. Gemini API via [Google AI Studio](https://makersuite.google.com/app).