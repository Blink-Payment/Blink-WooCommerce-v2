<?php
function insertArrayAtPosition( $array, $insert, $position ) { 
	/*
	$array : The initial array i want to modify
	$insert : the new array i want to add, eg array('key' => 'value') or array('value')
	$position : the position where the new array will be inserted into. Please mind that arrays start at 0
	*/
	return array_slice($array, 0, $position, true) + $insert + array_slice($array, $position, null, true);
}
