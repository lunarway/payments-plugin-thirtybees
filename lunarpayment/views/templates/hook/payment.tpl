{if $lunar_status == 'enabled'}
    <style type="text/css">
        .cards {
            display: inline-flex;
        }

        .cards li img {
            vertical-align: middle;
            margin-right: 10px;
            width: 37px;
            height: 27px;
        }
    </style>
    <script type="text/javascript" src="https://sdk.paylike.io/a.js"></script>
    <script>
        /** Initialize lunar public key object. */
        var LUNAR_PUBLIC_KEY = {
            key: "{$LUNAR_PUBLIC_KEY|escape:'htmlall':'UTF-8'}"
        };

        /** Initialize api client. */
        var lunar = Paylike(LUNAR_PUBLIC_KEY);

        /** Initialize payment variables. */
        var test_mode = "{$test_mode}";
        var shop_name = "{$shop_name|escape:'htmlall':'UTF-8'}";
        var PS_SSL_ENABLED = "{$PS_SSL_ENABLED|escape:'htmlall':'UTF-8'}";
        var host = "{$http_host|escape:'htmlall':'UTF-8'}";
        var BASE_URI = "{$base_uri|escape:'htmlall':'UTF-8'}";
        var popup_title = "{$popup_title nofilter}";
        var popup_description = "{$popup_description nofilter}"; //html variable can not be escaped;
        var currency_code = "{$currency_code|escape:'htmlall':'UTF-8'}";
        var amount = {$amount|escape:'htmlall':'UTF-8'};
        var exponent = {$exponent};
        var id_cart = {$id_cart}; //html variable can not be escaped;
        var products = {$products}; //html variable can not be escaped;
        var name = "{$name|escape:'htmlall':'UTF-8'}";
        var email = "{$email|escape:'htmlall':'UTF-8'}";
        var telephone = "{$telephone|escape:'htmlall':'UTF-8'}";
        var address = "{$address|escape:'htmlall':'UTF-8'}";
        var ip = "{$ip|escape:'htmlall':'UTF-8'}";
        var locale = "{$locale|escape:'htmlall':'UTF-8'}";
        var platform_version = "{$platform_version|escape:'htmlall':'UTF-8'}";
        var ecommerce = "{$ecommerce|escape:'htmlall':'UTF-8'}";
        var module_version = "{$module_version|escape:'htmlall':'UTF-8'}";
        var url_controller = "{$redirect_url|escape:'htmlall':'UTF-8'}";
        var qry_str = "{$qry_str}"; //html variable can not be escaped;

        function pay() {
            lunar.pay({
                    test: ('test' == test_mode) ? (true) : (false),
                    amount: {
                        currency: currency_code,
                        exponent: exponent,
                        value: amount
                    },
                    title: popup_title,
                    description: popup_description,
                    locale: locale,
                    custom: {
                        orderId: id_cart,
                        products: products,
                        customer: {
                            name: name,
                            email: email,
                            telephone: telephone,
                            address: address,
                            customerIp: ip
                        },
                        // platform: {
                        //     version: platform_version,
                        //     name: ecommerce
                        // },
                        ecommerce: {
                            version: platform_version,
                            name: ecommerce
                        },
                        lunar_module: {
                            version: module_version
                        }
                    },

                },
                function (err, r) {
                    if (typeof r !== 'undefined') {
                        var return_url = url_controller + qry_str + 'transactionid=' + r.transaction.id;
                        if (err) {
                            return console.warn(err);
                        }
                        location.href = htmlDecode(return_url);
                    }
                });
        }

        function htmlDecode(url) {
            return String(url).replace(/&amp;/g, '&');
        }
    </script>
    {*<div class="row">
        <div class="col-xs-12">
            <p class="payment_module lunar" onclick="pay();">
                <span class="lunar_text">{l s='Pay with credit card' mod='lunarpayment'}</span>
            </p>
        </div>
    </div>*}
    <div class="row">
        <div class="col-xs-12 col-md-12">
            <div class="payment_module lunar-payment clearfix" style="border: 1px solid #d6d4d4;
            border-radius: 4px;
            color: #333333;
            display: block;
            font-size: 17px;
            font-weight: bold;
            letter-spacing: -1px;
            line-height: 23px;
            padding: 20px 20px;
            position: relative;
            cursor:pointer;
            margin-top: 10px;
            " onclick="pay();">
                <input style="float:left;" id="lunar-btn" type="image" name="submit"
                        src="{$this_path_lunar}logo.png" alt=""
                        style="vertical-align: middle; margin-right: 10px; width:57px; height:57px;"/>
                <div style="float:left; margin-left:10px;">
                    <span style="margin-right: 10px;">{l s={$payment_method_title} mod='lunarpayment'}</span>
                    <span>
                        <ul class="cards">
                            {foreach from=$payment_method_creditcard_logo item=logo}
                                <li>
                                    <img src="{$this_path_lunar}/views/img/{$logo}" title="{$logo}" alt="{$logo}"/>
                                </li>
                            {/foreach}
                        </ul>
                    </span>
                    <small style="font-size: 12px; display: block; font-weight: normal; letter-spacing: 1px;">{l s={$payment_method_desc} mod='lunarpayment'}</small>
                </div>
            </div>
        </div>
    </div>
{/if}
