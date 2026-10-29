#!/bin/bash
# Human check 4 (P3/P2 x FlowMint), on the Local site only — never through the
# MCP relays, which point at a live site. Uses real HTTP submissions, FlowMint's
# own listener, run repository, delete-entries step and validator.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
WPX="$HERE/wpx"
URL="https://ai-section-builder.local/wp-admin/admin-ajax.php"
OUT="$HERE/flowmint-$(date -u +%Y%m%dT%H%M%SZ)"; mkdir -p "$OUT"
F=hc-flow-form; W=hc-flow

"$WPX" eval '
$cfg = array( "title" => "HC flowmint", "fields" => array(
  array( "key" => "name",  "type" => "text",  "label" => "Name",  "required" => true ),
  array( "key" => "email", "type" => "email", "label" => "Email", "required" => true ) ),
  "settings" => array( "notification" => array( "enabled" => false ),
    "spam_protection" => array( "min_submission_time" => 1, "rate_limit" => array( "max" => 1000, "window" => 3600 ) ),
    "availability" => array( "max_entries" => 2, "closes_at" => "2099-12-31", "closed_message" => "HC flow: full." ) ) );
$r = pforms_save_db_form( "hc-flow-form", "HC flowmint", wp_json_encode( $cfg ) );
if ( is_wp_error( $r ) ) { echo "form: ", $r->get_error_message(); exit( 1 ); }
$w = FMW_Workflow_Repository::create( array( "id" => "hc-flow", "title" => "HC flowmint check", "form_id" => "hc-flow-form", "enabled" => true,
  "config" => array( "steps" => array( array( "name" => "trace", "type" => "log_info", "config" => array( "message" => "HC flow run {{ run.id }} entry {{ entry.id }} {{ data.email }}" ) ) ) ) ) );
if ( is_wp_error( $w ) ) { echo "workflow: ", $w->get_error_message(); exit( 1 ); }
echo "setup ok\n";' || exit 2

# Render once while the form is open: later posts reuse it, like a cached page.
"$WPX" eval-file "$HERE/render-fields.php" "$F" > "$OUT/fields.tsv" || exit 2
post() {  # $1 = label
  "$WPX" eval 'foreach ( array( "::1", "127.0.0.1" ) as $ip ) { delete_transient( "pforms_global_ip_" . md5( $ip ) ); }'
  sleep 1.3
  local base=(); while IFS=$'\t' read -r k v; do base+=( --data-urlencode "$k=$v" ); done < "$OUT/fields.tsv"
  curl -sk -m 30 "$URL" --data-urlencode "action=pforms_submit_form" "${base[@]}" \
    --data-urlencode "pforms_field_name=Flow $1" --data-urlencode "pforms_field_email=flow-$1@example.test" \
    --data-urlencode "_pforms_submission_id=hc-flow-$1-$$" > "$OUT/resp-$1.json"
  echo "post $1: $(cat "$OUT/resp-$1.json")"
}

state() {  # prints entries / runs / count / availability
  "$WPX" eval '
  global $wpdb;
  $e = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}fre_entries WHERE form_id = \"hc-flow-form\" AND is_spam = 0 ORDER BY id" );
  $runs = $wpdb->get_results( "SELECT id, entry_id, status FROM {$wpdb->prefix}fmw_workflow_runs WHERE workflow_id = \"hc-flow\" ORDER BY id" );
  $a = PForms_Availability::check( "hc-flow-form" );
  echo "entries=[", implode( ",", $e ), "] runs=", count( $runs ), " [";
  foreach ( $runs as $r ) { echo "run ", $r->id, " entry ", $r->entry_id, " ", $r->status, "; "; }
  echo "] count=", ( new PForms_Place_Counter() )->get( "hc-flow-form" ), " open=", $a["open"] ? "yes" : "no", " reason=", $a["reason"], "\n";'
}

echo "== A. two accepted, third refused"
post 1; post 2; post 3
state | tee "$OUT/state-after-3-posts.txt"

echo "== B. execute the queued runs (Action Scheduler)"
"$WPX" action-scheduler run --hooks=fmw_run_workflow --batch-size=10 --batches=1 2>&1 | tail -2
"$WPX" action-scheduler run --batch-size=20 --batches=1 2>&1 | tail -1
state | tee "$OUT/state-after-runs.txt"

echo "== C. retention: FlowMint fre_delete_entries step deletes both entries"
"$WPX" eval '
global $wpdb;
$ids = array_map( "intval", $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}fre_entries WHERE form_id = \"hc-flow-form\"" ) );
$step = new FMW_Step_Fre_Delete_Entries( array( "name" => "purge", "config" => array( "entries" => $ids ) ) );
$out  = $step->execute( new FMW_Workflow_Context( 0, 0, "hc-flow", "hc-flow-form" ) );
echo "step output: ", wp_json_encode( $out ), "\n";'
state | tee "$OUT/state-after-retention.txt"
post 4
state | tee "$OUT/state-after-post-4.txt"

echo "== D. FlowMint validator on a workflow whose form has availability settings"
"$WPX" eval '
$w = FMW_Workflow_Repository::get( "hc-flow" );
$res = FMW_Workflow_Validator::validate_full( array( "id" => "hc-flow", "form_id" => "hc-flow-form", "config" => json_decode( is_array( $w ) ? $w["config"] : $w->config, true ) ) );
echo "validate_full: ", wp_json_encode( $res ), "\n";' | tee "$OUT/validator.txt"

echo "evidence: $OUT"
