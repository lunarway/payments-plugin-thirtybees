<script type="text/javascript">
$(document).ready(function() {
	var appendEl;
	appendEl = $('select[name=id_order_state]').parents('form').after($('<div/>'));
	$("#lunar").appendTo(appendEl);

    $('#lunar_action').bind('change', lunarActionChangeHandler);
    $('#submit_lunar_action').bind('click', submitLunarActionClickHandler);
});

    function lunarActionChangeHandler(e) {
        var option_value = $('#lunar_action option:selected').val();
        if(option_value == 'refund') {
            $('input[name="lunar_amount_to_refund"]').show();
            $('input[name="lunar_refund_reason"]').show();
        } else {
            $('input[name="lunar_amount_to_refund"]').hide();
            $('input[name="lunar_refund_reason"]').hide();
        }
    }

    function submitLunarActionClickHandler(e) {
        e.preventDefault();
        $('#alert').hide();
        var lunar_action = $('#lunar_action').val();
        var errorFlag = false;

        if(lunar_action == '') {
            var html = '<strong>Warning!</strong> Please select an action.';
            errorFlag = true;

        } else if(lunar_action == 'refund') {
            var refund_amount = $('input[name="lunar_amount_to_refund"]').val();
            var refund_reason = $('input[name="lunar_refund_reason"]').val();
            var html = '';

            if(refund_amount == '' && refund_reason == '') {
                var html = '<strong>Warning!</strong> Refund requires an amount and an refund reason.';
                errorFlag = true;

            } else if(refund_amount == '') {
                var html = '<strong>Warning!</strong> Please provide the refund amount.';
                errorFlag = true;

            } else if(refund_reason == '') {
                var html = '<strong>Warning!</strong> Please provide the refund reason.';
                errorFlag = true;
            }
        }

        if(errorFlag) {
            $('#alert').html(html);
            $('#alert').removeClass('alert-success')
                    .removeClass('alert-info')
                    .removeClass('alert-warning')
                    .removeClass('alert-danger')
                    .addClass('alert-warning');
            $('#alert').show();
            return false;
        }

        //Make an AJAX call for Lunar action
        $(e.currentTarget).button('loading');
        var url = $('#lunar_form').attr('action');
        $.ajax({
            url: url,
            type: 'POST',
            data: $('#lunar_form').serializeArray(),
            dataType: 'JSON',
            success: function(response) {
                $(e.currentTarget).button('reset');
                //console.log(response);

                if(response.hasOwnProperty('success') && response.hasOwnProperty('message')) {
                    var message = response.message;
                    var html = '<strong>Success!</strong> ' + message;
                    $('#alert').html(html);
                    $('#alert').removeClass('alert-success')
                            .removeClass('alert-info')
                            .removeClass('alert-warning')
                            .removeClass('alert-danger')
                            .addClass('alert-success');
                    $('#alert').show();
                    window.location = window.location;

                } else if(response.hasOwnProperty('warning') && response.hasOwnProperty('message')) {
                    var message = response.message;
                    var html = '<strong>Warning!</strong> ' + message;
                    $('#alert').html(html);
                    $('#alert').removeClass('alert-success')
                            .removeClass('alert-info')
                            .removeClass('alert-warning')
                            .removeClass('alert-danger')
                            .addClass('alert-warning');
                    $('#alert').show();

                } else if(response.hasOwnProperty('error') && response.hasOwnProperty('message')) {
                    var message = response.message;
                    var html = '<strong>Error!</strong> ' + message;
                    $('#alert').html(html);
                    $('#alert').removeClass('alert-success')
                            .removeClass('alert-info')
                            .removeClass('alert-warning')
                            .removeClass('alert-danger')
                            .addClass('alert-danger');
                    $('#alert').show();
                }
            },
            error: function(response) {
                console.log(response);
            }
        });

    }
</script>
<div id="lunar" class="row" style="margin-top:5%;">
	<div class="panel">
		<form id="lunar_form" action="{$link->getAdminLink('AdminOrders', false)|escape:'htmlall':'UTF-8'}&amp;id_order={$id_order|escape:'htmlall':'UTF-8'}&amp;vieworder&amp;token={$order_token|escape:'htmlall':'UTF-8'}" method="post">
			<fieldset {if $ps_version < 1.5}style="width: 400px;"{/if}>
				<legend class="panel-heading">
                    <img src="../img/admin/money.gif" alt="" />{l s='Process Lunar Payment' mod='lunarpayment'}
                </legend>
                <div id="alert" class="alert" style="display: none;"></div>
				<div class="form-group margin-form">
					<select class="form-control" id="lunar_action" name="lunar_action">
						<option value="">{l s='-- Select Lunar Action --' mod='lunarpayment'}</option>
                        {if $lunartransaction['captured'] == "NO"}
						    <option value="capture">{l s='Capture' mod='lunarpayment'}</option>
                        {/if}
						<option value="refund">{l s='Refund' mod='lunarpayment'}</option>
                        {if $lunartransaction['captured'] == "NO"}
						    <option value="void">{l s='Void' mod='lunarpayment'}</option>
                        {/if}
					</select>
				</div>

				<div class="form-group margin-form">
					<div class="col-md-6">
						<input class="form-control" name="lunar_amount_to_refund" style="display: none;" placeholder="{l s='Input refund amount' mod='lunarpayment'}" type="text"/>
					</div>
					<div class="col-md-6">
						<input class="form-control" name="lunar_refund_reason" style="display: none;" placeholder="{l s='Input refund reason' mod='lunarpayment'}" type="text"/>
					</div>
				</div>

				<div class="form-group margin-form">
					<input class="pull-right btn btn-default" name="submit_lunar_action" id="submit_lunar_action" type="submit" class="btn btn-primary" value="{l s='Process Action' mod='lunarpayment'}"/>
				</div>
			</fieldset>
		</form>
	</div>
</div>
