#!/bin/sh
# Run the whole suite in the right order.
#
# The two files are not independent: test_flow.py registers "samtaylor" through the
# public form and test_session.py then approves that account from the admin screen.
# _reset.php clears the account and the login lockout transients so a second run
# starts from the same place as the first.
set -e
HERE=$(dirname "$0")

php "$HERE/fam-wp/_reset.php"
python3 "$HERE/test_flow.py"
python3 "$HERE/test_session.py"
