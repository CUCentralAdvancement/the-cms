#!/usr/bin/env bash

# Install site if on review app. Import config if on other environments.
if [[ -z "$HEROKU_PR_NUMBER" ]]
  then
  echo "Not on a review app. No need to copy database."
else
  # Split db url into parts since the pg:copy command needs it to confirm deletion.
  # ${DB_NAME[3]} ends up being the database name.
  # IFS is the internal string separator which needs set to forward-slash for parsing db url string.
  IFS='/' read -r -a DB_NAME <<< "$DATABASE_URL"

  echo "Review app detected. Copying database from staging..."
#  heroku pg:reset -a $HEROKU_APP_NAME --confirm $HEROKU_APP_NAME
#  heroku pg:copy digital-dash-stage-adv-cu::DATABASE_URL $DATABASE_URL -a $HEROKU_APP_NAME --confirm ${DB_NAME[3]}

  # Attach Cloudinary from production.
#  CL_URL=$(heroku config:get CLOUDINARY_URL -a digital-dash-adv-cu)
#  /app/vendor/bin/drush state:set cua_cloudinary.cloudinary_url ${CL_URL} --input-format=string

  echo "Clearing cache..."
#  /app/vendor/bin/drush cr

  echo "Triggering test run...at https://digital-dash-stage-adv-cu.herokuapp.com/api/review-apps/${HEROKU_APP_NAME}/${HEROKU_BRANCH}"
#  curl https://digital-dash-stage-adv-cu.herokuapp.com/api/review-apps/${HEROKU_APP_NAME}/${HEROKU_BRANCH}

fi

  echo "Copying scaffolding files..."
  cp /app/scripts/templates/.htaccess /app/web/sites/default/files

  echo "Importing any config changes..."
  /app/vendor/bin/drush cim -y

  echo "Clearing cache..."
  /app/vendor/bin/drush cr


