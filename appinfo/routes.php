<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$id = ['id' => '[^/]+'];

return ['routes' => [
	['name' => 'scim#serviceProviderConfig', 'url' => '/v2/ServiceProviderConfig', 'verb' => 'GET'],

	['name' => 'scim#listUsers', 'url' => '/v2/Users', 'verb' => 'GET'],
	['name' => 'scim#createUser', 'url' => '/v2/Users', 'verb' => 'POST'],
	['name' => 'scim#getUser', 'url' => '/v2/Users/{id}', 'verb' => 'GET', 'requirements' => $id],
	['name' => 'scim#replaceUser', 'url' => '/v2/Users/{id}', 'verb' => 'PUT', 'requirements' => $id],
	['name' => 'scim#patchUser', 'url' => '/v2/Users/{id}', 'verb' => 'PATCH', 'requirements' => $id],
	['name' => 'scim#deleteUser', 'url' => '/v2/Users/{id}', 'verb' => 'DELETE', 'requirements' => $id],

	['name' => 'scim#listGroups', 'url' => '/v2/Groups', 'verb' => 'GET'],
	['name' => 'scim#createGroup', 'url' => '/v2/Groups', 'verb' => 'POST'],
	['name' => 'scim#getGroup', 'url' => '/v2/Groups/{id}', 'verb' => 'GET', 'requirements' => $id],
	['name' => 'scim#replaceGroup', 'url' => '/v2/Groups/{id}', 'verb' => 'PUT', 'requirements' => $id],
	['name' => 'scim#patchGroup', 'url' => '/v2/Groups/{id}', 'verb' => 'PATCH', 'requirements' => $id],
	['name' => 'scim#deleteGroup', 'url' => '/v2/Groups/{id}', 'verb' => 'DELETE', 'requirements' => $id],

	['name' => 'admin#save', 'url' => '/admin/settings', 'verb' => 'PUT'],
	['name' => 'admin#createToken', 'url' => '/admin/tokens', 'verb' => 'POST'],
	['name' => 'admin#deleteToken', 'url' => '/admin/tokens/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
]];
