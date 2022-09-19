<?php

declare(strict_types=1);

use RedisCachePro\Loggers\LoggerInterface;

class RedisCachePro_DebugBar_Insights extends RedisCachePro_DebugBar_Panel
{
    /**
     * Holds the object cache information object.
     *
     * @var object
     */
    protected $info;

    /**
     * Create a new insights panel instance.
     *
     * @param  object  $info
     */
    public function __construct($info)
    {
        $this->info = $info;
    }

    /**
     * The title of the panel.
     *
     * @return  string
     */
    public function title()
    {
        return 'Object Cache';
    }

    /**
     * Whether the panel is visible.
     *
     * @return  bool
     */
    public function is_visible()
    {
        return true;
    }

    /**
     * Render the panel.
     *
     * @var void
     */
    public function render()
    {
        require __DIR__ . '/templates/insights.phtml';
    }
}
