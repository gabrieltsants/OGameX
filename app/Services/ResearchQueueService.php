<?php

namespace OGame\Services;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Services\Objects\ObjectService;

/**
 * Class ResearchQueueService.
 *
 * ResearchQueueService object.
 *
 * @package OGame\Services
 */
class ResearchQueueService
{
    /**
     * Information about objects.
     *
     * @var ObjectService
     */
    protected ObjectService $objects;

    /**
     * The queue model where this class should get its data from.
     *
     * @var ResearchQueue
     */
    protected ResearchQueue $model;

    /**
     * BuildingQueue constructor.
     *
     * @param ObjectService $objects
     */
    public function __construct(ObjectService $objects)
    {
        $this->objects = $objects;

        $model_name = 'OGame\Models\ResearchQueue';
        $this->model = new $model_name();
    }

    /**
     * Retrieve queued items that are not being built yet.
     *
     * @return \Illuminate\Support\Collection
     *  Array when an item exists. False if it does not.
     */
    public function retrieveQueuedFromQueue(\Illuminate\Support\Collection $queue_items) : \Illuminate\Support\Collection
    {
        foreach ($queue_items as $key => $record) {
            if ($record['building'] == 1) {
                unset($queue_items[$key]);
            }
        }

        return $queue_items;
    }

    /**
     * Retrieve all build queue items that already should be finished for a planet.
     *
     * @param int $planet_id
     * The planet ID for which to retrieve the finished items.
     *
     * @return Collection
     */
    public function retrieveFinished(int $planet_id) : Collection
    {
        // Fetch queue items from model
        return $this->model->where([
            ['planet_id', $planet_id],
            ['time_end', '<=', Carbon::now()->timestamp],
            ['building', 1],
            ['processed', 0],
            ['canceled', 0],
        ])
            ->orderBy('time_start', 'asc')
            ->get();
    }

    /**
     * Retrieve all build queue items that already should be finished for a given user.
     */
    public function retrieveFinishedForUser(PlayerService $player) : \Illuminate\Support\Collection
    {
        // Fetch queue items from model
        return $this->model
            ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->where([
                ['users.id', $player->getId()],
                ['research_queues.time_end', '<=', Carbon::now()->timestamp],
                ['research_queues.building', 1],
                ['research_queues.processed', 0],
                ['research_queues.canceled', 0],
            ])
            ->select('research_queues.*')
            ->orderBy('research_queues.time_start', 'asc')
            ->get();
    }

    /**
     * Add a building to the building queue for the current planet.
     *
     * @param PlayerService $player
     * @param PlanetService $planet
     * @param int $building_id
     * @return void
     * @throws Exception
     */
    public function add(PlayerService $player, PlanetService $planet, int $building_id) : void
    {
        $build_queue = $this->retrieveQueue($planet);
        $build_queue = $this->enrich($build_queue);

        // Max amount of buildings that can be in the queue in a given time.
        $max_build_queue_count = 4; //@TODO: refactor into global / constant?
        if (count($build_queue) >= $max_build_queue_count) {
            // Max amount of build queue items already exist, throw exception.
            throw new Exception('Maximum number of items already in queue.');
        }

        $object = $this->objects->getResearchObjectById($building_id);

        // @TODO: add checks that current logged in user is owner of planet
        // and is able to add this object to the building queue.
        $current_level = $player->getResearchLevel($object->machine_name);

        // Check to see how many other items of this building there are already
        // in the queue, because if so then the level needs to be higher than that.
        $amount = $this->activeBuildingQueueItemCount($player, $building_id);
        $next_level = $current_level + $amount + 1;

        $queue = new $this->model;
        $queue->planet_id = $planet->getPlanetId();
        $queue->object_id = $building_id;
        $queue->object_level_target = $next_level;

        // Save the new queue item
        $queue->save();

        // Set the new queue item to start (if applicable)
        $this->start($player);
    }

    /**
     * Retrieve current building build queue for a planet.
     *
     * @param PlanetService $planet
     * @return \Illuminate\Support\Collection
     */
    public function retrieveQueue(PlanetService $planet) : \Illuminate\Support\Collection
    {
        // Fetch queue items from model
        return $this->model
            ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->where([
                ['users.id', $planet->getPlayer()->getId()],
                ['research_queues.processed', 0],
                ['research_queues.canceled', 0],
            ])
            ->select('research_queues.*')
            ->orderBy('research_queues.time_start', 'asc')
            ->get();
    }

    /**
     * Enriches one or more queue_items to prepare it for rendering.
     *
     * @param $queue_items
     *  Single queue_item or array of queue_items.
     *
     * @return array<int, array<string, mixed>>|array<string, mixed>
     */
    public function enrich($queue_items) : array
    {
        // Enrich information before we return it
        $return = array();

        if (!$queue_items) {
            return $return;
        }

        // Convert single queue_item result to an array because the logic
        // beneath expects an array.
        $return_type = 'array';
        if (!empty($queue_items->id)) {
            $return_type = 'single';
            $queue_items = array($queue_items);
        }

        foreach ($queue_items as $item) {
            $object = $this->objects->getResearchObjectById($item->object_id);

            $time_countdown = $item->time_end - Carbon::now()->timestamp;
            if ($time_countdown < 0) {
                $time_countdown = 0;
            }

            $return[] = [
                'id' => $item->id,
                'object' => [
                    'id' => $object->id,
                    'title' => $object->title,
                    'level_target' => $item->object_level_target,
                    'assets' => $object->assets,
                ],
                'time_countdown' => $time_countdown,
                'time_total' => $item->time_end - $item->time_start,
            ];
        }

        if ($return_type == 'single') {
            return $return[0];
        }

        return $return;
    }

    /**
     * Get the amount of already existing queue items for a particular
     * building.
     *
     * @param PlayerService $player
     * @param int $building_id
     * @return int
     */
    public function activeBuildingQueueItemCount(PlayerService $player, int $building_id) : int
    {
        // Fetch queue items from model
        return $this->model
            ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->where([
                ['users.id', $player->getId()],
                ['research_queues.object_id', $building_id],
                ['research_queues.processed', 0],
                ['research_queues.canceled', 0],
            ])
            ->count();
    }

    /**
     * Start building the next item in the queue (if available).
     *
     * This actually starts the building process and deducts the resources
     * from the planet. If there are not enough resources the build attempt
     * will fail.
     *
     * @param PlayerService $player
     *
     * @param int $time_start
     *  Optional parameter to indicate when the new item should start, this
     *  is used for when a few build queue items are finished at the exact
     *  same time, e.g. when a user closes its session and logs back in
     *  after a while.
     * @return void
     * @throws Exception
     */
    public function start(PlayerService $player, int $time_start = 0) : void
    {
        $queue_items = $this->model
            ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
            ->join('users', 'planets.user_id', '=', 'users.id')
            ->where([
                ['users.id', $player->getId()],
                ['building', 0],
                ['processed', 0],
                ['canceled', 0],
            ])
            ->select('research_queues.*')
            ->get();

        foreach ($queue_items as $queue_item) {
            $planet = $player->planets->childPlanetById($queue_item->planet_id);

            $object = $this->objects->getResearchObjectById($queue_item->object_id);

            // See if the planet has enough resources for this build attempt.
            $price = $this->objects->getObjectPrice($object->machine_name, $planet);
            $build_time = $player->planets->current()->getTechnologyResearchTime($object->machine_name);

            // Only start the queue item if there are no other queue items building
            // for this planet.
            $build_queue = $this->retrieveQueue($planet);
            $currently_building = $this->retrieveCurrentlyBuildingFromQueue($build_queue);

            if (!empty($currently_building)) {
                // There already is something else building, don't start a new one.
                break;
            }

            // Sanity check: check if the target level as stored in the database
            // is 1 higher than the current level. If not, then it means something
            // is wrong.
            $current_level = $player->getResearchLevel($object->machine_name);
            if ($queue_item->object_level_target != ($current_level + 1)) {
                // Error, cancel build queue item.
                $this->cancel($player, $planet, $queue_item->id, $queue_item->object_id);

                continue;
            }

            // Sanity check: check if the planet has enough resources. If not,
            // then cancel build request.
            if (!$planet->hasResources($price)) {
                // Error, cancel build queue item.
                $this->cancel($player, $planet, $queue_item->id, $queue_item->object_id);

                continue;
            }

            // All OK, deduct resources and start building process.
            $planet->deductResources($price);

            if (!$time_start) {
                $time_start = Carbon::now()->timestamp;
            }

            $queue_item->time_duration = (int)$build_time;
            $queue_item->time_start = $time_start;
            $queue_item->time_end = $queue_item->time_start + $queue_item->time_duration;
            $queue_item->building = 1;
            $queue_item->metal = (int)$price->metal->get();
            $queue_item->crystal = (int)$price->crystal->get();
            $queue_item->deuterium = (int)$price->deuterium->get();
            $queue_item->save();

            // If the calculated end time is lower than the current time,
            // we force that the planet is updated again which will grant
            // the building immediately without having to wait for a refresh.
            if ($queue_item->time_end < Carbon::now()->timestamp) {
                // @TODO: refactor the planet update logic so this method
                // can call only the part it needs directly without causing
                // a major overhead.
                $planet->update();
            }
        }
    }

    /**
     * Retrieve the item that is currently being build (if any).
     *
     * @return bool|ResearchQueue
     *  Array when an item exists. False if it does not.
     */
    public function retrieveCurrentlyBuildingFromQueue(\Illuminate\Support\Collection $queue_items) : bool|ResearchQueue
    {
        foreach ($queue_items as $key => $record) {
            if ($record['building'] == 1) {
                return $record;
            }
        }

        return false;
    }

    /**
     * Cancels an active building queue record.
     *
     * @param PlayerService $player
     * @param PlanetService $planet
     * @param int $building_queue_id
     * @param int $building_id
     * @throws Exception
     */
    public function cancel(PlayerService $player, PlanetService $planet, int $building_queue_id, int $building_id): void
    {
        // @TODO: add user owner verify checks.
        $queue_item = $this->model->where([
            ['id', $building_queue_id],
            ['planet_id', $planet->getPlanetId()],
            ['object_id', $building_id],
            ['processed', 0],
            //['time_end', '>', Carbon::now()->timestamp], //@TODO: add back in time_end when building processing logic works.
        ])->first();

        // If object is found: add canceled flag.
        if ($queue_item) {
            // Gets all building queue records of this target level and all that
            // come after it. So e.g. if user cancels build order for metal mine
            // level 5 then any other already queued build orders for lvl 6,7,8 etc.
            // will also be canceled.
            $queue_items_higher_level = $this->model->where([
                ['planet_id', $planet->getPlanetId()],
                ['object_id', $building_id],
                ['object_level_target', '>', $queue_item->object_level_target],
                ['processed', 0],
                //['time_end', '>', Carbon::now()->timestamp], //@TODO: add back in time_end when building processing logic works.
            ])->get();

            // Add canceled flag to all entries with a higher level (if any).
            foreach ($queue_items_higher_level as $queue_item_higher_level) {
                $queue_item_higher_level->building = 0;
                $queue_item_higher_level->canceled = 1;

                $queue_item_higher_level->save();
            }

            // Give back resources if the current entry was already building.
            if ($queue_item->building == 1) {
                $planet->addResources(new Resources($queue_item->metal, $queue_item->crystal, $queue_item->deuterium, 0));
            }

            // Add canceled flag to the main entry.
            $queue_item->building = 0;
            $queue_item->canceled = 1;

            $queue_item->save();

            // Set the next queue item to start (if applicable)
            $this->start($player);
        }
    }
}
