<?php

namespace Codemenco\Gateway;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Codemenco\Gateway\GatewayResolver
 */
class Gateway extends Facade
{
	/**
	 * The name of the binding in the IoC container.
	 *
	 * @return string
	 */
	protected static function getFacadeAccessor()
	{
		return 'gateway';
	}
}
