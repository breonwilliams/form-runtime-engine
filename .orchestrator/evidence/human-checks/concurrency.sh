#!/bin/bash
# Human check 1 (P2/P3): N simultaneous real HTTP submissions to a form capped
# at 1, repeated ROUNDS times, each round on a fresh form. Pass = in every
# round exactly one success, every other response form_closed, exactly one
# stored non-spam entry, and a place count of 1.
#
# Usage: concurrency.sh [ROUNDS] [N]
set -u
ROUNDS=${1:-20}
N=${2:-10}
HERE="$(cd "$(dirname "$0")" && pwd)"
WPX="$HERE/wpx"
URL="https://ai-section-builder.local/wp-admin/admin-ajax.php"
OUT="$HERE/concurrency-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$OUT"
pass=0; fail=0

for r in $(seq 1 "$ROUNDS"); do
  id="hc-conc-$r-$$"
  rd="$OUT/round-$r"; mkdir -p "$rd"

  HC_ID="$id" "$WPX" eval '
    $cfg = array(
      "title"  => "HC concurrency",
      "fields" => array(
        array( "key" => "name",  "type" => "text",  "label" => "Name",  "required" => true ),
        array( "key" => "email", "type" => "email", "label" => "Email", "required" => true ),
      ),
      "settings" => array(
        "notification"    => array( "enabled" => false ),
        "spam_protection" => array( "honeypot" => true, "timing_check" => true, "min_submission_time" => 1,
                                    "rate_limit" => array( "max" => 1000, "window" => 3600 ) ),
        "availability"    => array( "max_entries" => 1 ),
      ),
    );
    $r = pforms_save_db_form( getenv( "HC_ID" ), "HC concurrency", wp_json_encode( $cfg ) );
    if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() ); exit( 1 ); }
  ' || { echo "round $r: could not create form"; exit 2; }

  "$WPX" eval-file "$HERE/render-fields.php" "$id" > "$rd/fields.tsv" || { echo "round $r: render failed"; exit 2; }
  sleep 1.3   # min_submission_time is 1 second

  # The per-IP limit (20/hour, a transient, no filter) would otherwise refuse
  # requests across rounds and mask the cap. Clear it for both loopback forms.
  "$WPX" eval 'foreach ( array( "::1", "127.0.0.1" ) as $ip ) { delete_transient( "pforms_global_ip_" . md5( $ip ) ); }'

  base=()
  while IFS=$'\t' read -r k v; do base+=( --data-urlencode "$k=$v" ); done < "$rd/fields.tsv"

  for i in $(seq 1 "$N"); do
    curl -sk -m 30 -o "$rd/resp-$i.json" -w "%{http_code}" "$URL" \
      --data-urlencode "action=pforms_submit_form" \
      "${base[@]}" \
      --data-urlencode "pforms_field_name=Racer $r-$i" \
      --data-urlencode "pforms_field_email=racer-$r-$i@example.test" \
      --data-urlencode "_pforms_submission_id=hc-$r-$i-$$-$RANDOM" > "$rd/code-$i.txt" &
  done
  wait

  ok=$(grep -l '"success":true' "$rd"/resp-*.json 2>/dev/null | wc -l | tr -d ' ')
  closed=$(grep -l '"code":"form_closed"' "$rd"/resp-*.json 2>/dev/null | wc -l | tr -d ' ')
  other=$(( N - ok - closed ))
  db=$(HC_ID="$id" "$WPX" eval '
    global $wpdb;
    $rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fre_entries WHERE form_id = %s AND is_spam = 0", getenv( "HC_ID" ) ) );
    $spam = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fre_entries WHERE form_id = %s AND is_spam = 1", getenv( "HC_ID" ) ) );
    echo $rows, " ", $spam, " ", ( new PForms_Place_Counter() )->get( getenv( "HC_ID" ) );
  ')
  read -r rows spam count <<< "$db"

  if [ "$ok" = 1 ] && [ "$closed" = $(( N - 1 )) ] && [ "$rows" = 1 ] && [ "$spam" = 0 ] && [ "$count" = 1 ]; then
    verdict=PASS; pass=$((pass+1))
  else
    verdict=FAIL; fail=$((fail+1))
  fi
  echo "round $r: $verdict  success=$ok form_closed=$closed other=$other  entries=$rows spam=$spam count=$count" | tee -a "$OUT/summary.txt"

  # Clean up: the round's entries, then the form (which removes its count row).
  HC_ID="$id" "$WPX" eval '
    global $wpdb; $e = new PForms_Entry();
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fre_entries WHERE form_id = %s", getenv( "HC_ID" ) ) ) as $eid ) { $e->delete( (int) $eid ); }
    pforms_delete_db_form( getenv( "HC_ID" ) );
  '
done

echo "TOTAL: $pass passed, $fail failed of $ROUNDS rounds x $N parallel requests" | tee -a "$OUT/summary.txt"
echo "evidence: $OUT"
[ "$fail" = 0 ]
