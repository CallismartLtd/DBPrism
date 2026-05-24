<?php
/**
 * Lock mode enum file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */

namespace Callismart\DBPrism\Utils;

enum LockMode {
    case NONE;
    case SHARED;
    case EXCLUSIVE;
    case NO_WAIT;
    case SKIP_LOCKED;
}