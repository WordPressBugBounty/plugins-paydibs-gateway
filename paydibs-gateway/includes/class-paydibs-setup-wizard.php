<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin setup wizard for Paydibs gateway onboarding.
 */
class Paydibs_Payment_Gateway_Setup_Wizard
{
    const PAGE_SLUG = 'paydibs-setup-wizard';
    const REDIRECT_OPTION = 'paydibs_gateway_setup_wizard_redirect';
    const COMPLETED_OPTION = 'paydibs_gateway_setup_wizard_completed';

    /**
     * @var string
     */
    protected $gateway_id;

    /**
     * @param string $gateway_id
     */
    public function __construct($gateway_id)
    {
        $this->gateway_id = $gateway_id;
    }

    /**
     * Whether the current user may access the setup wizard.
     *
     * @return bool
     */
    protected function user_can_access_wizard()
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /**
     * Register admin hooks for wizard flow.
     */
    public function register_hooks()
    {
        add_action('admin_menu', [$this, 'register_hidden_admin_page']);
        add_action('admin_init', [$this, 'maybe_redirect_after_activation']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_wizard_assets']);
    }

    /**
     * Enqueue assets required by the wizard UI.
     *
     * @param string $hook_suffix
     */
    public function enqueue_wizard_assets($hook_suffix)
    {
        if ('admin.php' !== $GLOBALS['pagenow']) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (self::PAGE_SLUG !== $page) {
            return;
        }

        wp_enqueue_style('dashicons');
    }

    /**
     * Register a hidden admin page so WordPress allows admin.php?page=paydibs-setup-wizard.
     *
     * A page slug that is not registered is blocked by core ("you are not allowed to access this page").
     */
    public function register_hidden_admin_page()
    {
        add_submenu_page(
            '',
            __('Paydibs Setup Wizard', 'paydibs-gateway'),
            __('Paydibs Setup Wizard', 'paydibs-gateway'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Redirect admin to wizard once after first activation.
     */
    public function maybe_redirect_after_activation()
    {
        if (!is_admin() || !$this->user_can_access_wizard() || wp_doing_ajax()) {
            return;
        }

        if ('yes' !== get_option(self::REDIRECT_OPTION, 'no')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (self::PAGE_SLUG === $page) {
            return;
        }

        delete_option(self::REDIRECT_OPTION);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * Render wizard page and handle submissions.
     */
    public function render_page()
    {
        if (!$this->user_can_access_wizard()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'paydibs-gateway'));
        }

        $step = isset($_GET['step']) ? max(1, min(4, (int) sanitize_text_field(wp_unslash($_GET['step'])))) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $settings = $this->get_gateway_settings();
        $errors = [];

        if ('POST' === $_SERVER['REQUEST_METHOD']) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            check_admin_referer('paydibs_setup_wizard_step_' . $step);
            $result = $this->handle_step_submission($step, $settings);
            if (!empty($result['errors'])) {
                $errors = $result['errors'];
                $settings = $result['settings'];
            } else {
                $next_step = (int) $result['next_step'];
                if (!empty($result['completed'])) {
                    update_option(self::COMPLETED_OPTION, 'yes');
                }
                wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&step=' . $next_step));
                exit;
            }
        }

        $this->render_markup($step, $settings, $errors);
    }

    /**
     * Get persisted WooCommerce gateway settings.
     *
     * @return array
     */
    protected function get_gateway_settings()
    {
        $settings = get_option('woocommerce_' . $this->gateway_id . '_settings', []);
        return is_array($settings) ? $settings : [];
    }

    /**
     * Save WooCommerce gateway settings.
     *
     * @param array $settings
     * @return void
     */
    protected function save_gateway_settings(array $settings)
    {
        update_option('woocommerce_' . $this->gateway_id . '_settings', $settings);
    }

    /**
     * Handle per-step submission.
     *
     * @param int   $step
     * @param array $settings
     * @return array
     */
    protected function handle_step_submission($step, $settings)
    {
        $errors = [];

        switch ($step) {
            case 1:
                $mode = isset($_POST['paydibs_mode']) ? sanitize_text_field(wp_unslash($_POST['paydibs_mode'])) : 'test'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if (!in_array($mode, ['test', 'live'], true)) {
                    $mode = 'test';
                }
                $settings['enabled'] = 'yes';
                $settings['test_mode'] = ('live' === $mode) ? 'no' : 'yes';
                $this->save_gateway_settings($settings);
                return ['next_step' => 2, 'errors' => [], 'settings' => $settings];

            case 2:
                $settings['test_tenant_id'] = isset($_POST['test_tenant_id']) ? sanitize_text_field(wp_unslash($_POST['test_tenant_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $settings['test_secrect_key'] = isset($_POST['test_secrect_key']) ? sanitize_text_field(wp_unslash($_POST['test_secrect_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $settings['test_currency'] = isset($_POST['test_currency']) ? sanitize_text_field(wp_unslash($_POST['test_currency'])) : 'AUTO'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

                if ('' === $settings['test_tenant_id'] || '' === $settings['test_secrect_key']) {
                    $errors[] = esc_html__('Test Merchant ID and Test Secret Key are required.', 'paydibs-gateway');
                }

                if (empty($errors)) {
                    $this->save_gateway_settings($settings);
                    return ['next_step' => 3, 'errors' => [], 'settings' => $settings];
                }
                break;

            case 3:
                $settings['live_tenant_id'] = isset($_POST['live_tenant_id']) ? sanitize_text_field(wp_unslash($_POST['live_tenant_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $settings['live_secrect_key'] = isset($_POST['live_secrect_key']) ? sanitize_text_field(wp_unslash($_POST['live_secrect_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $settings['live_currency'] = isset($_POST['live_currency']) ? sanitize_text_field(wp_unslash($_POST['live_currency'])) : 'AUTO'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

                $this->save_gateway_settings($settings);
                return ['next_step' => 4, 'errors' => [], 'settings' => $settings];

            case 4:
                $settings['debug_mode'] = isset($_POST['debug_mode']) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $settings['cod_enabled'] = isset($_POST['cod_enabled']) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $settings['test_mode'] = isset($_POST['go_live']) ? 'no' : 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $this->save_gateway_settings($settings);
                return ['next_step' => 4, 'errors' => [], 'completed' => true, 'settings' => $settings];
        }

        return ['next_step' => $step, 'errors' => $errors, 'settings' => $settings];
    }

    /**
     * Currencies used in wizard selects.
     *
     * @return array
     */
    protected function get_supported_currencies_for_wizard()
    {
        $currencies = [
            'MYR' => 'MYR',
            'SGD' => 'SGD',
            'PHP' => 'PHP',
            'VND' => 'VND',
            'HKD' => 'HKD',
            'IDR' => 'IDR',
            'KHR' => 'KHR',
            'KRW' => 'KRW',
            'THB' => 'THB',
            'CNY' => 'CNY',
        ];

        return ['AUTO' => __('Auto (use store currency)', 'paydibs-gateway')] + apply_filters('paydibs_payment_gateway_supported_currencies', $currencies);
    }

    /**
     * Render markup for wizard page.
     *
     * @param int   $step
     * @param array $settings
     * @param array $errors
     */
    protected function render_markup($step, $settings, $errors)
    {
        $supported_currencies = $this->get_supported_currencies_for_wizard();
        $completion = (int) round(($step / 4) * 100);
        ?>
        <div class="wrap paydibs-wizard-wrap">
            <style>
                .paydibs-wizard-wrap { max-width: 920px; margin-top: 18px; }
                .paydibs-wizard-card { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 24px; box-shadow: 0 1px 2px rgba(0, 0, 0, .04); }
                .paydibs-wizard-header h1 { margin: 0 0 6px; font-size: 26px; }
                .paydibs-wizard-subtitle { margin: 0; color: #50575e; }
                .paydibs-progress { height: 8px; border-radius: 99px; background: #f0f0f1; margin: 18px 0 14px; overflow: hidden; }
                .paydibs-progress > span { display: block; height: 100%; background: #2271b1; }
                .paydibs-step-list { display: flex; gap: 8px; margin: 0 0 18px; padding: 0; list-style: none; flex-wrap: wrap; }
                .paydibs-step-list li { padding: 6px 10px; border-radius: 99px; background: #f6f7f7; color: #50575e; font-size: 12px; }
                .paydibs-step-list li.is-active { background: #e6f1fb; color: #0a4b78; font-weight: 600; }
                .paydibs-wizard-section h2 { margin-top: 0; font-size: 19px; }
                .paydibs-wizard-section p { color: #50575e; }
                .paydibs-radio-card { border: 1px solid #dcdcde; border-radius: 8px; padding: 12px; margin-bottom: 10px; }
                .paydibs-field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 12px 0; align-items: start; }
                .paydibs-field-grid .paydibs-field-full { grid-column: 1 / -1; }
                .paydibs-field-grid .paydibs-field { display: flex; flex-direction: column; }
                .paydibs-field-grid label { font-weight: 600; display: block; margin-bottom: 6px; }
                .paydibs-input-wrap,
                .paydibs-password-wrap {
                    position: relative;
                    display: flex;
                    align-items: center;
                    min-height: 32px;
                }
                .paydibs-field-grid input.regular-text,
                .paydibs-field-grid select {
                    width: 100%;
                    max-width: 100%;
                    box-sizing: border-box;
                    min-height: 32px;
                    height: 32px;
                    margin: 0;
                }
                .paydibs-password-wrap input { padding-right: 42px; }
                .paydibs-password-toggle {
                    position: absolute;
                    top: 50%;
                    right: 6px;
                    transform: translateY(-50%);
                    border: 0;
                    background: transparent;
                    color: #50575e;
                    cursor: pointer;
                    padding: 4px;
                    line-height: 1;
                }
                .paydibs-help { font-size: 12px; color: #646970; margin-top: 6px; }
                .paydibs-actions { margin-top: 18px; display: flex; gap: 10px; align-items: center; }
                .paydibs-note { margin-top: 12px; padding: 10px 12px; background: #f6f7f7; border-left: 3px solid #2271b1; }
                .paydibs-mode-switch { display: inline-flex; align-items: center; gap: 12px; margin-top: 8px; padding: 10px 12px; border: 1px solid #dcdcde; border-radius: 10px; background: #f9f9f9; }
                .paydibs-switch { position: relative; display: inline-block; width: 48px; height: 26px; }
                .paydibs-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
                .paydibs-switch-slider { position: absolute; inset: 0; background: #2271b1; border-radius: 99px; transition: .2s; }
                .paydibs-switch-slider:before { content: ""; position: absolute; height: 20px; width: 20px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .2s; }
                .paydibs-switch input:not(:checked) + .paydibs-switch-slider { background: #7e8993; }
                .paydibs-switch input:not(:checked) + .paydibs-switch-slider:before { transform: translateX(22px); }
                .paydibs-mode-label { font-weight: 600; color: #1d2327; }
                @media (max-width: 782px) { .paydibs-field-grid { grid-template-columns: 1fr; } }
            </style>

            <div class="paydibs-wizard-card">
                <div class="paydibs-wizard-header">
                    <h1><?php echo esc_html__('Paydibs Setup Wizard', 'paydibs-gateway'); ?></h1>
                    <p class="paydibs-wizard-subtitle"><?php echo esc_html__('Complete your gateway setup in a few guided steps.', 'paydibs-gateway'); ?></p>
                </div>

                <div class="paydibs-progress"><span style="width: <?php echo esc_attr((string) $completion); ?>%;"></span></div>
                <ul class="paydibs-step-list">
                    <li class="<?php echo 1 === $step ? 'is-active' : ''; ?>"><?php echo esc_html__('1. Mode', 'paydibs-gateway'); ?></li>
                    <li class="<?php echo 2 === $step ? 'is-active' : ''; ?>"><?php echo esc_html__('2. Test Credentials', 'paydibs-gateway'); ?></li>
                    <li class="<?php echo 3 === $step ? 'is-active' : ''; ?>"><?php echo esc_html__('3. Live Credentials', 'paydibs-gateway'); ?></li>
                    <li class="<?php echo 4 === $step ? 'is-active' : ''; ?>"><?php echo esc_html__('4. Finalize', 'paydibs-gateway'); ?></li>
                </ul>

                <?php foreach ($errors as $error) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
                <?php endforeach; ?>

                <?php if (1 === $step) : ?>
                    <div class="paydibs-wizard-section">
                        <h2><?php echo esc_html__('Choose startup mode', 'paydibs-gateway'); ?></h2>
                        <p><?php echo esc_html__('Use the switch to choose where you want transactions to run first.', 'paydibs-gateway'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('paydibs_setup_wizard_step_1'); ?>
                            <?php $is_test_mode = ('yes' === ($settings['test_mode'] ?? 'yes')); ?>
                            <input type="hidden" name="paydibs_mode" id="paydibs_mode" value="<?php echo esc_attr($is_test_mode ? 'test' : 'live'); ?>">
                            <div class="paydibs-mode-switch">
                                <span class="paydibs-mode-label"><?php echo esc_html__('Live', 'paydibs-gateway'); ?></span>
                                <label class="paydibs-switch" for="paydibs_mode_toggle">
                                    <input type="checkbox" id="paydibs_mode_toggle" <?php checked($is_test_mode); ?>>
                                    <span class="paydibs-switch-slider"></span>
                                </label>
                                <span class="paydibs-mode-label"><?php echo esc_html__('Test (recommended)', 'paydibs-gateway'); ?></span>
                            </div>
                            <p class="paydibs-help"><?php echo esc_html__('Switch ON for Test mode, OFF for Live mode.', 'paydibs-gateway'); ?></p>
                            <div class="paydibs-actions">
                                <button type="submit" class="button button-primary"><?php echo esc_html__('Continue', 'paydibs-gateway'); ?></button>
                            </div>
                        </form>
                    </div>
                <?php elseif (2 === $step) : ?>
                    <div class="paydibs-wizard-section">
                        <h2><?php echo esc_html__('Configure test environment', 'paydibs-gateway'); ?></h2>
                        <p><?php echo esc_html__('Add staging credentials used for sandbox checkout testing.', 'paydibs-gateway'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('paydibs_setup_wizard_step_2'); ?>
                            <div class="paydibs-field-grid">
                                <div class="paydibs-field">
                                    <label for="test_tenant_id"><?php echo esc_html__('Test Merchant ID', 'paydibs-gateway'); ?></label>
                                    <div class="paydibs-input-wrap">
                                        <input name="test_tenant_id" id="test_tenant_id" class="regular-text" value="<?php echo esc_attr($settings['test_tenant_id'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="paydibs-field">
                                    <label for="test_secrect_key"><?php echo esc_html__('Test Secret Key', 'paydibs-gateway'); ?></label>
                                    <div class="paydibs-password-wrap">
                                        <input type="password" name="test_secrect_key" id="test_secrect_key" class="regular-text" value="<?php echo esc_attr($settings['test_secrect_key'] ?? ''); ?>">
                                        <button type="button" class="paydibs-password-toggle" data-target="test_secrect_key" aria-label="<?php echo esc_attr__('Show secret key', 'paydibs-gateway'); ?>">
                                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="paydibs-field paydibs-field-full">
                                    <label for="test_currency"><?php echo esc_html__('Test Currency', 'paydibs-gateway'); ?></label>
                                    <div class="paydibs-input-wrap">
                                        <select name="test_currency" id="test_currency">
                                            <?php foreach ($supported_currencies as $code => $label) : ?>
                                                <option value="<?php echo esc_attr($code); ?>" <?php selected(($settings['test_currency'] ?? 'AUTO'), $code); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <p class="paydibs-help"><?php echo esc_html__('Use AUTO to follow your WooCommerce store currency.', 'paydibs-gateway'); ?></p>
                                </div>
                            </div>
                            <div class="paydibs-actions">
                                <button type="submit" class="button button-primary"><?php echo esc_html__('Save and continue', 'paydibs-gateway'); ?></button>
                            </div>
                        </form>
                    </div>
                <?php elseif (3 === $step) : ?>
                    <div class="paydibs-wizard-section">
                        <h2><?php echo esc_html__('Configure production environment', 'paydibs-gateway'); ?></h2>
                        <p><?php echo esc_html__('You can set live credentials now or keep this empty and complete later.', 'paydibs-gateway'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('paydibs_setup_wizard_step_3'); ?>
                            <div class="paydibs-field-grid">
                                <div class="paydibs-field">
                                    <label for="live_tenant_id"><?php echo esc_html__('Live Merchant ID', 'paydibs-gateway'); ?></label>
                                    <div class="paydibs-input-wrap">
                                        <input name="live_tenant_id" id="live_tenant_id" class="regular-text" value="<?php echo esc_attr($settings['live_tenant_id'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="paydibs-field">
                                    <label for="live_secrect_key"><?php echo esc_html__('Live Secret Key', 'paydibs-gateway'); ?></label>
                                    <div class="paydibs-password-wrap">
                                        <input type="password" name="live_secrect_key" id="live_secrect_key" class="regular-text" value="<?php echo esc_attr($settings['live_secrect_key'] ?? ''); ?>">
                                        <button type="button" class="paydibs-password-toggle" data-target="live_secrect_key" aria-label="<?php echo esc_attr__('Show secret key', 'paydibs-gateway'); ?>">
                                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="paydibs-field paydibs-field-full">
                                    <label for="live_currency"><?php echo esc_html__('Live Currency', 'paydibs-gateway'); ?></label>
                                    <div class="paydibs-input-wrap">
                                        <select name="live_currency" id="live_currency">
                                            <?php foreach ($supported_currencies as $code => $label) : ?>
                                                <option value="<?php echo esc_attr($code); ?>" <?php selected(($settings['live_currency'] ?? 'AUTO'), $code); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="paydibs-actions">
                                <button type="submit" class="button button-primary"><?php echo esc_html__('Save and continue', 'paydibs-gateway'); ?></button>
                            </div>
                        </form>
                    </div>
                <?php else : ?>
                    <div class="paydibs-wizard-section">
                        <h2><?php echo esc_html__('Finalize setup', 'paydibs-gateway'); ?></h2>
                        <p><?php echo esc_html__('Choose optional behaviors and complete onboarding.', 'paydibs-gateway'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('paydibs_setup_wizard_step_4'); ?>
                            <p>
                                <label>
                                    <input type="checkbox" name="debug_mode" value="yes" <?php checked(($settings['debug_mode'] ?? 'no'), 'yes'); ?>>
                                    <?php echo esc_html__('Enable debug logging', 'paydibs-gateway'); ?>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="cod_enabled" value="yes" <?php checked(($settings['cod_enabled'] ?? 'no'), 'yes'); ?>>
                                    <?php echo esc_html__('Enable COD fields', 'paydibs-gateway'); ?>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="go_live" value="yes" <?php checked(($settings['test_mode'] ?? 'yes'), 'no'); ?>>
                                    <?php echo esc_html__('Switch to Live mode now', 'paydibs-gateway'); ?>
                                </label>
                            </p>
                            <div class="paydibs-note">
                                <?php echo esc_html__('Tip: If you keep Test mode enabled, you can safely run trial orders before going live.', 'paydibs-gateway'); ?>
                            </div>
                            <div class="paydibs-actions">
                                <button type="submit" class="button button-primary"><?php echo esc_html__('Finish setup', 'paydibs-gateway'); ?></button>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->gateway_id)); ?>">
                                    <?php echo esc_html__('Open gateway settings', 'paydibs-gateway'); ?>
                                </a>
                            </div>
                        </form>
                        <?php if ('yes' === get_option(self::COMPLETED_OPTION, 'no')) : ?>
                            <div class="notice notice-success"><p><?php echo esc_html__('Setup complete. Reopen this wizard anytime at WooCommerce > Settings > Payments > Paydibs Gateway, or visit the setup wizard URL after activation.', 'paydibs-gateway'); ?></p></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
            (function () {
                var modeToggle = document.getElementById('paydibs_mode_toggle');
                var modeField = document.getElementById('paydibs_mode');
                if (modeToggle && modeField) {
                    modeToggle.addEventListener('change', function () {
                        modeField.value = modeToggle.checked ? 'test' : 'live';
                    });
                }

                var toggles = document.querySelectorAll('.paydibs-password-toggle');
                toggles.forEach(function (toggleButton) {
                    toggleButton.addEventListener('click', function () {
                        var targetId = toggleButton.getAttribute('data-target');
                        var input = document.getElementById(targetId);
                        if (!input) {
                            return;
                        }
                        var isPassword = input.getAttribute('type') === 'password';
                        input.setAttribute('type', isPassword ? 'text' : 'password');
                        toggleButton.innerHTML = isPassword
                            ? '<span class="dashicons dashicons-hidden" aria-hidden="true"></span>'
                            : '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>';
                    });
                });
            })();
        </script>
        <?php
    }
}
