=== Paydibs Gateway for WooCommerce ===
Contributors: paydibs
Tags: woocommerce, payment gateway, paydibs, malaysia, fpx
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 4.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Paydibs is a Malaysian WooCommerce payment gateway plugin for FPX, online banking, e-wallets, and cards with fast, secure integration.

== Description ==

Accept Payments Seamlessly with Paydibs

Paydibs Payment Gateway for WooCommerce enables Malaysian businesses to securely accept online payments through FPX, online banking, eWallets, credit and debit cards, BNPL, and more. Designed for seamless WooCommerce integration, this plugin helps merchants simplify checkout and improve payment acceptance across multiple payment methods.

= Features =

* Quick and easy WooCommerce integration
* All your payment methods in one place
* Supports saved cards for faster checkout (logged-in customers)
* Safe and reliable payments
* User-friendly checkout experience

= Supported payment methods =

* FPX – Online Banking Payments
* FPX B2B – Corporate Online Banking
* Credit & Debit Cards – Visa, Mastercard & UnionPay
* Malaysian eWallets – Touch 'n Go eWallet, Boost, GrabPay, MCash and more
* Buy Now Pay Later (BNPL) – AhaPay, Atome, PayLater by Grab & SPayLater
* Cash on Delivery (COD)
* Cross Border Payment Channels – Alipay+, PayNow and more
* DuitNow QR

= Payment flow =

1. Customer selects Paydibs during checkout
2. Customer is shown a secure payment page
3. Payment is completed
4. Paydibs sends payment confirmation back to WooCommerce
5. Order status updates automatically

= How to get started? =

Set up your Paydibs Payment Gateway on WooCommerce quickly with our [step-by-step integration guide](https://paydibs.com/woocommerce-payment-gateway-guide/).

= About Paydibs =

Paydibs is a Malaysian payment gateway provider offering secure online payment solutions for businesses of all sizes. Our WooCommerce payment plugin supports FPX, online banking, eWallets, cards, BNPL, and other digital payment methods to help merchants streamline online payment acceptance.

= Requirements =

This plugin requires a Paydibs payment gateway subscription to connect to WooCommerce. For more information about Paydibs services please visit: https://paydibs.com/

This plugin connects to the Paydibs payment service. By using this plugin you agree to Paydibs terms and privacy policy available on the Paydibs website: https://paydibs.com/

== External services ==

This plugin relies on Paydibs-hosted payment APIs to process WooCommerce orders. The service is required to send the shopper to Paydibs to pay, to receive payment status callbacks, and to verify transactions.

* **Live API endpoint:** `https://v3api.paydibs.com/PPG/trigger` — used when Test mode is disabled in the gateway settings.
* **Staging API endpoint:** `https://stgapi.paydibs.com/PPG/trigger` — used when Test mode is enabled.

Technical reference for request/response fields and signing (Pay Request, Pay Response, Query Request): [Paydibs API v3 documentation](https://v3api-docs.paydibs.com/docs/api-reference/pay-request).

**When data is sent**

* **Checkout redirect (customer's browser):** When the customer places an order and chooses Paydibs, the plugin builds a signed request and redirects the browser to the Paydibs URL above. Data includes order amount, currency, order and payment reference IDs, your configured merchant ID, return and callback URLs on your site, product description, and customer details needed for payment and fraud checks (for example billing name, email, phone, IP address, user agent, and optional billing or shipping address fields). If Cash on Delivery fields are enabled, receiver and parcel details are included as required by Paydibs for COD channels.
* **Server callback:** Paydibs sends payment result data to your store's WooCommerce callback URL (JSON or form fields). The plugin reads only the parameters required for that callback, verifies the signature, and may call the same Paydibs API with a read-only **QUERY** request to confirm the transaction before updating the order.

**Legal**

Paydibs provides this service. Terms and privacy:

* Terms of service: https://paydibs.com/paydibs-service-agreement/
* Privacy policy: https://paydibs.com/privacy-policy/

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/paydibs-gateway` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Complete the setup wizard on first activation, or go to WooCommerce > Settings > Payments and enable Paydibs Gateway.
4. Configure your Paydibs test and live credentials in the gateway settings.

== Screenshots ==

1. Paydibs gateway configuration in WooCommerce settings.
2. Setup wizard for test and live credential onboarding.
3. Checkout page – credit and debit card payment.
4. Checkout page – online banking (FPX).
5. Checkout page – e-wallet payment.

== FAQ ==

= Do I need a Paydibs merchant account to use this plugin? =

Yes. A Paydibs merchant account is required to activate and use the Paydibs payment gateway plugin. Merchants can complete onboarding before connecting the plugin to their WooCommerce store.

= How do I install and set up the Paydibs plugin? =

Setup is quick and simple. Install the Paydibs WooCommerce plugin from WordPress, activate it, and enter your Paydibs API credentials in the WooCommerce settings.

= What payment methods does Paydibs support? =

Paydibs supports multiple online payment methods including FPX online banking, DuitNow QR, Malaysian eWallets, credit and debit cards, BNPL, QR payments, and cross border payment channels through a single WooCommerce payment gateway integration.

= Can customers save their card details for future purchases? =

Yes, for logged-in customers. Paydibs supports secure card tokenization, allowing returning customers to check out faster without re-entering their details.

= Is Paydibs secure? =

Yes. Paydibs uses secure payment processing with fraud monitoring and transaction verification features to help merchants process online payments safely and reliably.

= Can I test payments before going live? =

Yes. Merchants can perform payment testing in a sandbox environment before going live to ensure the WooCommerce payment integration is configured correctly.

= Which languages are supported? =

English (default), Bahasa Melayu (ms_MY), Chinese Simplified (zh_CN), Chinese Traditional (zh_TW), Hindi (hi_IN), and Tamil (ta_IN). Set your WordPress site language under Settings → General to use a translation.

= My question is not listed. =

For more information about the Paydibs WooCommerce payment gateway plugin, please refer to the [Paydibs FAQ](https://paydibs.com/faq/) page.

== Changelog ==
= 4.0.7 =
* Harden payment callbacks: bind checkout to order meta, cross-check QUERY vs callback fields, and complete orders only when payment reference, amount, currency, and order id all match.

= 4.0.6 =
* Fix bundled translations not loading: call load_plugin_textdomain() before gateway initializes.
* Update WordPress.org short description copy.

= 4.0.5 =
* Add full translations for Malay, Chinese (Simplified and Traditional), Hindi, and Tamil.
* Improve gateway and checkout copy; clarify test mode notices for FPX and banking.
* Align setup wizard field heights; remove unused translation strings.

= 4.0.4 =
* Fix setup wizard admin page registration and permissions.
* Prevent duplicate payment callback hooks when Blocks checkout loads the gateway.
* Reuse the main gateway instance in Blocks integration.

= 4.0.3 =
* Replace plugin directory banners with high-resolution PNG exports at correct WordPress sizes.

= 4.0.2 =
* Limit plugin tags to five for WordPress.org directory compliance.

= 4.0.1 =
* Update WordPress.org listing copy, banners, screenshots, and FAQ.
* Version bump for directory asset refresh.

= 4.0.0 =
* Add a first-run setup wizard for Test and Live credential onboarding.
* Move setup wizard logic into a dedicated class for maintainability.
* Refresh wizard UI with a clean, minimal admin experience and guided step flow.
* Send CustReferenceId for logged-in customers to support saved-card flows.

= 3.0.8 =
* Declare WooCommerce as a required plugin in readme/plugin headers. Translate the checkout test-mode description line.
* Plugin Check: remove manual `load_plugin_textdomain()` (discouraged for WordPress.org-hosted plugins; core loads translations for the plugin slug). PHPCS ignore for the integer `RuntimeException` HTTP status argument (`ExceptionNotEscaped` false positive).

= 3.0.7 =
* Plugin Check: do not ship hidden files (for example `.gitignore`) in the WordPress.org zip. Escape exception messages for `WordPress.Security.EscapeOutput.ExceptionNotEscaped`; translate previously hard-coded exception strings.

= 3.0.6 =
* Harden QUERY (sendQueryRequest) handling: require HTTP 200, decode JSON with strict error handling, allowlist fields before signature verification, sanitize returned fields after verification, and use translatable/safe error messages.

= 3.0.5 =
* Use is_plugin_active for WooCommerce detection (multisite / network-friendly); remove unreachable code after payment responses; drop unused large asset; minor cleanup.

= 3.0.4 =
* QUERY requests include all validated Pay Response fields again (same as before), rebuilt from a strict allowlist with outbound sanitization so no extra parameters are sent; Pay Response signature check uses hash_equals with case-normalized hex; escape payment icon URL/alt; clarify JSON decode handling for gateway responses; link official API docs from readme.

= 3.0.3 =
* WordPress.org review: document external services in readme; sanitize and validate Paydibs callback input without reading full superglobals; use wp_json_encode for callback responses; rename gateway classes to Paydibs-prefixed names (avoid WC_ prefix). Developer note: paydibs_gateway_* filters renamed to paydibs_payment_gateway_*.

= 3.0.2 =
* Fix Plugin Check warnings and cleanup.

= 3.0.0 =
* Add optional customer details to Pay Request payload.
* Code cleanup and repo compliance updates.
* Add COD fields and settings.

= 2.1.0 =
* Add optional customer details to Pay Request payload.
* Code cleanup and repo compliance updates.

= 2.0.0 =
* Initial release.
