<input type="checkbox" id="{$option->optionName}" {if $value} checked="checked"{/if} name="values[{$option->optionName}]" value="1" {if $disableOptions || $enableOptions}class="jsEnablesOptions" data-disable-options="[ {@$disableOptions}]" data-enable-options="[ {@$enableOptions}]" {/if} />