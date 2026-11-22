<?php

namespace Modules\Hotel\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\Hotel\Models\HotelRoom;
use Modules\Hotel\Models\HotelFloor;
use Modules\Hotel\Models\HotelRent;

class HotelReceptionController extends Controller
{
	public function index()
	{
		$rooms = $this->getRooms();

		if (request()->ajax()) {
			return response()->json([
				'success' => true,
				'rooms'   => $rooms,
			], 200);
		}
		$floors = HotelFloor::where('active', true)
				->orderBy('description')
				->get();

		$roomStatus = HotelRoom::$status;

		return view('hotel::rooms.reception', compact('rooms', 'floors', 'roomStatus'));
	}

	private function getRooms()
	{
		$rooms = HotelRoom::with('category', 'floor', 'rates');

		if (request('hotel_floor_id')) {
			$rooms = $rooms->where('hotel_floor_id', request('hotel_floor_id'));
		}
		if (request('status')) {
			$rooms = $rooms->where('status', request('status'));
		}

		$rooms = $rooms->get();
		$occupiedRoomIds = $rooms->where('status', 'OCUPADO')->pluck('id');
		$rentsByRoom = collect();
		if ($occupiedRoomIds->isNotEmpty()) {
			$rentsByRoom = HotelRent::whereIn('id', function ($query) use ($occupiedRoomIds) {
					$query->selectRaw('MAX(id)')
						->from('hotel_rents')
						->whereIn('hotel_room_id', $occupiedRoomIds)
						->groupBy('hotel_room_id');
				})
				->orderBy('id', 'DESC')
				->get()
				->keyBy('hotel_room_id');
		}

		$rooms->each(function ($room) use ($rentsByRoom) {
			if ($room->status === 'OCUPADO') {
				$room->rent = $rentsByRoom->get($room->id);
			} else {
				$room->rent = [];
			}

			return $room;
		});

		return $rooms;
	}
}
