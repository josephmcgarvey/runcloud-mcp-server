<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tool Profile
    |--------------------------------------------------------------------------
    |
    | Controls which tools the MCP server exposes. Every registered tool's schema
    | is sent to the client on each request, so a narrower profile both reduces
    | context cost and removes whole classes of action from what the model can
    | reach at all — a stronger guarantee than any in-tool confirmation.
    |
    | readonly  Only tools that cannot change anything.
    | standard  Read, create, and modify. Excludes anything that deletes.
    | full      Everything, including deletions.
    |
    */

    'profile' => env('RUNCLOUD_MCP_PROFILE', 'standard'),

];
