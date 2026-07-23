#!/usr/bin/env bash
#
# find-orphan-databases.sh — report databases that no web app appears to reference.
#
# RunCloud's own "linked database" metadata is unreliable: applications connect using their own
# config files, which RunCloud never reads. On MCG Development Team, 106 of 107 web apps had no
# link recorded at all, so this walks the filesystem instead.
#
# READ ONLY. It prints findings and never drops, alters, or deletes anything.
#
# Usage:  sudo bash find-orphan-databases.sh [/home]
#
set -uo pipefail

ROOT="${1:-/home}"

DATABASES=(
    "jh_pdiemea_wp"
    "mcg_directus"
    "dd_pdiemea_wp"
    "jh_jclegacy"
    "dd_pdihc_wp"
    "jh_pdihc_wp"
    "dd_trud_wp"
    "ls_airmatic_c5"
    "ss_myxnav_c5"
    "ls_pdihc_wp"
    "ls_jcweb_c5"
    "ls_jcweb_dev_c5"
    "dd_waynemills_c5"
    "ls_frankford_c5"
    "ls_waynemills_c5"
    "ja_airmatic_c5"
    "ss_myxnav_c5_demo"
    "dd_scsa_c5"
    "ja_workmaster_c5"
    "dd_ccf_wp"
    "wooTesting_1665687251"
    "dd_workmaster_c5"
    "dd_airmatic_c5"
    "airmatic_c5"
    "dd_holiday"
    "dd_fumb_c5"
    "dd_jc_edu_c5"
    "tt_airmatic_c5"
    "dd_concrete_template_c5"
    "tt_jc_edu_c5"
    "dc_jc_edu_c5"
    "dc_jcweb_c5"
    "tt_workmaster_concrete"
    "np_pdihc_wp"
    "dd_thecfgp_c5"
    "dc_fumb_c5"
    "jm_workmaster_c5"
    "np_workmaster_c5"
    "np_airmatic_c5"
    "cfgp_c5_staging_clone"
    "dd_cfgpnew_c5"
    "tt_netsuite_portal"
    "jm_mcg_machform"
    "dc_workmaster_c5"
    "dc_pdihc_wp"
    "dd_mcgweb_c5"
    "jm_fumb_c5"
    "dd_scs_wp"
    "dd_bha_c5"
    "dc_jcedu_c5_new"
    "dc_jerseycollege_c5"
    "jm_ccms_foundation"
    "dd_nicepak_c5"
    "dc_pdiemea_wp"
    "tt_frankford_c5"
    "jm_airmatic_c5"
    "airmatic_c5_dev_20231106"
    "jm_client_project_portal"
    "mcg_dev_holiday_2023"
    "dc_nicepak_c5"
    "dc_ccaweb_c5"
    "dc_cca_c5"
    "dd_ccaweb_c5_2024"
    "dc_ccaweb_c5_2024_clone"
    "ss_myxnav_c5_demo_clone"
    "dc_dbe_wp"
    "dc_cfgp_c5"
    "dc_thecfgp_c5"
    "ss_jc_edu_c5"
    "dc_waynemills_c5"
    "dc_wayne_mills_c5"
    "jm_grimeboss_ccms"
    "jm_store_locator"
    "jm_machform_sandbox"
    "dd_ccms_testsite"
    "dd_dbe_wp"
    "jm_jc_machform"
    "dc_mcg_c5"
    "dd_ns_companion"
    "jm_waynemills_ccms"
    "jm_custportal_241118"
    "jm_shade_portal_test"
    "jm_ns_companion_test"
    "dd_ns_companion_v2"
    "jm_wetnap_ccms"
    "dd_workmaster_legacy"
    "dd_vsrtechnology_legacy"
    "dc_wetnap"
    "dc_wetnap_machform"
    "dd_recasens_c5"
    "dc_airmatic_c5"
    "dd_frankford_machform"
    "dd_frankfordnew_c5"
    "jm_myxnav_c5"
    "dd_frankfordnew_machform"
)

if [[ ! -d "$ROOT" ]]; then
    echo "No such directory: $ROOT" >&2
    exit 1
fi

echo "Scanning $ROOT for database references across ${#DATABASES[@]} databases..."
echo

# Collect every file worth searching once, rather than re-walking per database.
# Config files first, then a broad net for anything else holding a connection string.
mapfile -t FILES < <(
    find "$ROOT" \
        -path '*/node_modules' -prune -o \
        -path '*/vendor' -prune -o \
        -path '*/.git' -prune -o \
        -type f \( \
            -name 'database.php' -o \
            -name 'wp-config.php' -o \
            -name '.env' -o \
            -name 'config.php' -o \
            -name 'site.php' -o \
            -name 'settings.php' -o \
            -name 'app.php' -o \
            -name '*.ini' -o \
            -name '*.yml' -o \
            -name '*.yaml' \
        \) -print 2>/dev/null
)

echo "Config files found: ${#FILES[@]}"
echo

if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "Nothing to search. Is the path right, and are you running with enough privilege to read it?" >&2
    exit 1
fi

REFERENCED=()
ORPHANS=()

for db in "${DATABASES[@]}"; do
    # Word-boundary match so short names do not match longer ones by accident,
    # e.g. dc_cca_c5 must not be satisfied by dc_ccaweb_c5.
    if grep -rlqE "(^|[^A-Za-z0-9_])${db}([^A-Za-z0-9_]|$)" "${FILES[@]}" 2>/dev/null; then
        REFERENCED+=("$db")
    else
        ORPHANS+=("$db")
    fi
done

echo "=============================================================="
echo " REFERENCED: ${#REFERENCED[@]}"
echo "=============================================================="
for db in "${REFERENCED[@]}"; do
    where=$(grep -rlE "(^|[^A-Za-z0-9_])${db}([^A-Za-z0-9_]|$)" "${FILES[@]}" 2>/dev/null | head -3 | sed "s|^$ROOT/||" | paste -sd'; ')
    printf '  %-34s %s\n' "$db" "$where"
done

echo
echo "=============================================================="
echo " NO REFERENCE FOUND: ${#ORPHANS[@]}"
echo "=============================================================="
echo " Candidates only. Read the caveats below before acting on any."
echo
for db in "${ORPHANS[@]}"; do
    printf '  %s\n' "$db"
done

echo
echo "--------------------------------------------------------------"
echo "A database can appear here and still be in use:"
echo "  - referenced from a file this scan did not match"
echo "  - connected to from another server, or by a cron job or CLI tool"
echo "  - the live target of a config value built at runtime"
echo "  - held as a deliberate backup of something that still matters"
echo
echo "Before deleting anything, check it is genuinely idle:"
echo "  mysql -e \"SELECT table_schema, SUM(data_length+index_length)/1024/1024 AS mb,"
echo "              MAX(update_time) AS last_write FROM information_schema.tables"
echo "              GROUP BY table_schema ORDER BY last_write;\""
echo
echo "and take a dump first:  mysqldump DBNAME > DBNAME-$(date +%F).sql"
echo "--------------------------------------------------------------"
