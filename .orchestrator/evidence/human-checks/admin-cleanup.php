<?php
foreach ( array( 'hc-admin-plain', 'hc-admin-capped', 'hc-admin-before', 'hc-admin-past', 'hc-admin-full', 'hc-admin-uncap' ) as $id ) { pforms_delete_db_form( $id ); }

echo "admin fixtures removed\n";
