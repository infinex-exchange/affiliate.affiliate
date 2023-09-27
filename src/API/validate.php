<?php

function validateRefid($refid) {
    if(!is_int($refid)) return false;
    if($refid < 1) return false;
    return true;
}

?>