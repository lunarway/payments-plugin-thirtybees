/// <reference types="cypress" />

'use strict';

import { PluginTestHelper } from './test_helper.js';

export var TestMethods = {

    /** Admin & frontend user credentials. */
    StoreUrl: (Cypress.env('ENV_ADMIN_URL').match(/^(?:http(?:s?):\/\/)?(?:[^@\n]+@)?(?:www\.)?([^:\/\n?]+)/im))[0],
    AdminUrl: Cypress.env('ENV_ADMIN_URL'),
    RemoteVersionLogUrl: Cypress.env('REMOTE_LOG_URL'),
    CaptureMode: 'Delayed',

    /** Construct some variables to be used bellow. */
    ShopName: 'thirtybees',
    VendorName: 'lunar',
    ModulesAdminUrl: '/index.php?controller=AdminModules',
    ManageEmailSettingUrl: '/index.php?controller=AdminEmails',
    OrdersPageAdminUrl: '/index.php?controller=AdminOrders',

    /**
     * Login to admin backend account
     */
     loginIntoAdminBackend() {
        cy.loginIntoAccount('input[name=email]', 'input[name=passwd]', 'admin');
    },
    /**
     * Login to client|user frontend account
     */
     loginIntoClientAccount() {
        cy.get('#blockuserinfo-login').click();
        cy.loginIntoAccount('#email', 'input[name=passwd]', 'client');
    },

    /**
     * Modify plugin settings
     */
    changeCaptureMode() {
        /** Go to modules page, and select payment method. */
        cy.goToPage(this.ModulesAdminUrl);

        /** Select payment gateways. */
        cy.get('#filter_payments_gateways').click();

        cy.get('a[href*="configure=lunarpayment&tab_module=payments_gateways"').click();
        cy.wait(1000);

        /** Change capture mode. */
        cy.get('#LUNAR_CHECKOUT_MODE').select(this.CaptureMode);
        cy.get('#module_form_submit_btn').click();
    },

    /**
     * Make an instant payment
     * @param {String} currency
     */
    makePaymentFromFrontend(currency) {
        /** Go to store frontend. */
        cy.goToPage(this.StoreUrl);

        /** Change currency & wait for products price to finish update. */
        cy.get('#blockcurrencies .dropdown-toggle').click();
        cy.get('#blockcurrencies ul a').each(($listLink) => {
            if ($listLink.text().includes(currency)) {
                cy.get($listLink).click();
            }
        });
        cy.wait(1000);

        /** Make all add-to-cart buttons visible. */
        PluginTestHelper.setVisibleOn('.product_list.grid .button-container');

        cy.wait(1000);

        /** Add to cart random product. */
        var randomInt = PluginTestHelper.getRandomInt(/*max*/ 6);
        cy.get('.ajax_add_to_cart_button').eq(randomInt).click();

        /** Proceed to checkout. */
        cy.get('.next a').click();
        cy.get('.standard-checkout').click();

        /** Continue checkout. */
        cy.get('button[name=processAddress]').click();
        cy.get('#cgv').click();
        cy.get('.standard-checkout').click();

        /** Verify amount. */
        cy.get('#total_price').then(($totalAmount) => {
            var expectedAmount = PluginTestHelper.filterAndGetAmountInMinor($totalAmount, currency);
            cy.window().then(($win) => {
                expect(expectedAmount).to.eq(Number($win.amount))
            })
        });

        /** Click on payment method. */
        cy.get('#lunar-btn').click();

        /**
         * Fill in payment popup.
         */
        PluginTestHelper.fillAndSubmitPopup();

        cy.wait(1000);

        /** Check if order was paid. */
        cy.get('.alert-success').should('contain.text', 'Congratulations, your payment has been approved');
    },

    /**
     * Make payment with specified currency and process order
     *
     * @param {String} currency
     * @param {String} paymentAction
     */
     payWithSelectedCurrency(currency, paymentAction) {
        /** Make an instant payment. */
        it(`makes a payment with "${currency}"`, () => {
            this.makePaymentFromFrontend(currency);
        });

        /** Process last order from admin panel. */
        it(`process (${paymentAction}) an order from admin panel`, () => {
            this.processOrderFromAdmin(paymentAction, currency);
        });
    },

    /**
     * Process last order from admin panel
     * @param {String} paymentAction
     * @param {String} currency
     */
     processOrderFromAdmin(paymentAction, currency = '') {
        /** Go to admin orders page. */
        cy.goToPage(this.OrdersPageAdminUrl);

        /** Click on first (latest in time) order from orders table. */
        cy.get('.table.order tbody tr').first().click();

        /**
         * If CaptureMode='Delayed', set shipped on order status & make 'capture'/'void'
         * If CaptureMode='Instant', set refunded on order status & make 'refund'
         */
         this.paymentActionOnOrderAmount(paymentAction, currency);
    },

    /**
     * Capture an order amount
     * @param {String} paymentAction
     * @param {String} currency
     * @param {Boolean} partialRefund
     */
     paymentActionOnOrderAmount(paymentAction, currency = '', partialRefund = false) {
        cy.get('#lunar_action').select(paymentAction);

        /** Get random 1 | 0. */
        var random  = PluginTestHelper.getRandomInt(/*max*/ 1);
        if (1 === random) {
            partialRefund = true;
        }

        /** Enter full amount for refund. */
        if ('refund' === paymentAction) {
            cy.get('#total_order  .amount').then(($totalAmount) => {
                var majorAmount = PluginTestHelper.filterAndGetAmountInMajorUnit($totalAmount, currency);
                /**
                 * Subtract 2 from amount.
                 * Assume that we do not have products with total amount of 2 units
                 */
                if (partialRefund) {
                    majorAmount -= 2
                }
                cy.get('input[name=lunar_amount_to_refund]').clear().type(`${majorAmount}`);
                cy.get('input[name=lunar_refund_reason]').clear().type('automatic refund');
            });
        }

        cy.get('#submit_lunar_action').click();
        cy.wait(1000);
        cy.get('#alert.alert-info').should('not.exist');
        cy.get('#alert.alert-warning').should('not.exist');
        cy.get('#alert.alert-danger').should('not.exist');
    },

    /**
     * Get Shop & plugin versions and send log data.
     */
     logVersions() {
        cy.get('#shop_version').then(($shopVersionFromPage) => {
            var footerText = $shopVersionFromPage.text();
            var shopVersion = footerText.replace(/[^0-9.]/g, '');
            cy.wrap(shopVersion).as('shopVersion');
        });

        /** Go to system settings admin page. */
        cy.goToPage(this.ModulesAdminUrl);

        /** Select payment gateways. */
        cy.get('#filter_payments_gateways').click();

        cy.get('.table #anchorLunarpayment .module_name').then($pluginVersionFromPage => {
            var pluginVersion = ($pluginVersionFromPage.text()).replace(/[^0-9.]/g, '');
            /** Make global variable to be accessible bellow. */
            cy.wrap(pluginVersion).as('pluginVersion');
        });

        /** Get global variables and make log data request to remote url. */
        cy.get('@shopVersion').then(shopVersion => {
            cy.get('@pluginVersion').then(pluginVersion => {

                cy.request('GET', this.RemoteVersionLogUrl, {
                    key: shopVersion,
                    tag: this.ShopName,
                    view: 'html',
                    ecommerce: shopVersion,
                    plugin: pluginVersion
                }).then((resp) => {
                    expect(resp.status).to.eq(200);
                });
            });
        });
    },

    /**
     * Modify email settings (disable notifications)
     */
    deactivateEmailNotifications() {
        /** Go to email settings page. */
        cy.goToPage(this.ManageEmailSettingUrl);

        cy.get('#PS_MAIL_METHOD_3').click();
        cy.get('#mail_fieldset_email .panel-footer button').click();
    },

    /**
     * Modify email settings (disable notifications)
     */
    activateEmailNotifications() {
        /** Go to email settings page. */
        cy.goToPage(this.ManageEmailSettingUrl);

        cy.get('#PS_MAIL_METHOD_1').click();
        cy.get('#mail_fieldset_email .panel-footer button').click();
    },

    /**
     * TEMPORARY ADDED BEGIN
     */
    enableThisModuleDisableOther() {
        cy.goToPage(this.ModulesAdminUrl);
        cy.get('#filter_payments_gateways').click();
        cy.get('a[href*="&module_name=lunarpayment&enable=1"').click();
        cy.wait(1000);

        cy.goToPage(this.ModulesAdminUrl);
        cy.get('#filter_payments_gateways').click();
        cy.get('a[href*="configure=paylikepayment"').siblings('button').click();
        cy.get('li a[href*="&module_name=paylikepayment&enable=0"').click();
        cy.wait(1000);
    },
    disableThisModuleEnableOther() {
        cy.goToPage(this.ModulesAdminUrl);
        cy.get('#filter_payments_gateways').click();
        cy.get('a[href*="configure=lunarpayment"').siblings('button').click();
        cy.get('li a[href*="&module_name=lunarpayment&enable=0"').click();
        cy.wait(1000);

        cy.goToPage(this.ModulesAdminUrl);
        cy.get('#filter_payments_gateways').click();
        cy.get('a[href*="&module_name=paylikepayment&enable=1"').click();
        cy.wait(1000);
    },
    /**
     * TEMPORARY ADDED END
     */
}
