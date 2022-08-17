<p class="alert alert-danger">
    {l s='Note: Due to standards we need to abide to, currencies decimals must match the lunar supported decimals. In order to use the Lunar module for the following currencies, you\'ll need to set "Number of decimals" option to the number shown bellow from tab. Since this is a global setting that affects all currencies you cannot use at the same time currencies with different decimals.' mod='lunarpayment'}
    <a href="{$preferences_url}">{l s='Preferences -> General' mod='lunarpayment'}</a>
    <br>
    {foreach from=$warning_currencies_decimal key=decimals item=currency}
        {foreach from=$currency item=iso_code}
            <b>{$iso_code} {l s='supports only' mod='lunarpayment'} {$decimals} {l s='decimals' mod='lunarpayment'}</b>
            <br/>
        {/foreach}
    {/foreach}
</p>