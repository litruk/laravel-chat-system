<?php
namespace Myckhel\ChatSystem\Traits\ChatEvent;

use Illuminate\Database\Eloquent\Model;
/**
 *
 */
interface HasMakeChatEvent
{
  public function chatEventMakers(Model $model = null, $id = null, $type = null, $made_id = null, $made_type = null);
}

?>
