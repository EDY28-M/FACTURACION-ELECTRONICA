<?php

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Resources\Json\ResourceCollection;

class UserCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function toArray($request)
    {
        return $this->collection->transform(function($row, $key) {

            $type = '';
            switch ($row->type) {
                case 'admin':
                    $type =  'Administrador' ;
                    break;
                case 'seller':
                    $type =  'Vendedor' ;
                        break;
                case 'client':
                    $type =  'Cliente' ;
                    break;
                default:
                    # code...
                    break;
            }

            return [
                'id' => $row->id,
                'email' => $row->email,
                'name' => $row->name,
                // The list never carries the full credential; it is only revealed in the user form.
                'api_token_hint' => $row->apiTokenHint(),
                'establishment_description' => optional($row->establishment)->description,
                'type' => $type,
                'is_owner' => $row->isOwner(),
                'locked' => (bool) $row->locked,

            ];
        });
    }
}
