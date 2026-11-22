<?php

namespace App\Http\Resources\Tenant;

use App\Support\Access\PermissionTree;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
	/**
	 * Transform the resource into an array.
	 *
	 * @param  \Illuminate\Http\Request
	 * @return array
	 */
	public function toArray($request)
	{
		$isOwner = $this->isOwner();
		$modulesInUser = $this->moduleIds();
		$levelsInUser = $this->levelIds();

		return [
			'id'               => $this->id,
			'email'            => $this->email,
			'name'             => $this->name,
			'api_token'        => $this->apiTokenVisibleTo($request->user()) ? $this->api_token : null,
			'api_token_hint'   => $this->apiTokenHint(),
			'establishment_id' => $this->establishment_id,
			'type'             => $this->type,
			'is_owner'         => $isOwner,
			'modules'          => array_map(function ($id) {
				return ['id' => $id, 'checked' => true];
			}, $modulesInUser),
			'levels'           => array_map(function ($id) {
				return ['id' => $id, 'checked' => true];
			}, $levelsInUser),
			'locked'           => (bool) $this->locked,
			'dataSource'       => PermissionTree::build($modulesInUser, $levelsInUser),
		];
	}
}
