rm -rf build-release aura-historia-partner-connect.zip
mkdir -p build-release

rsync -a ./ build-release/aura-historia-partner-connect/ \
  --exclude .git \
  --exclude .github \
  --exclude build-release \
  --exclude aura-historia-partner-connect.zip \
  --exclude node_modules \
  --exclude vendor \
  --exclude tests \
  --exclude scripts \
  --exclude openapi \
  --exclude .wp-env.json \
  --exclude .wp-env.override.json \
  --exclude .phpunit.result.cache \
  --exclude package.json \
  --exclude package-lock.json \
  --exclude phpunit.xml.dist \
  --exclude README.md \
  --exclude .gitignore \
  --exclude .gitattributes

cd build-release/aura-historia-partner-connect
composer install --no-dev --prefer-dist --optimize-autoloader
rm -f composer.json composer.lock
cd ..

zip -r ../aura-historia-partner-connect.zip aura-historia-partner-connect
cd ..
