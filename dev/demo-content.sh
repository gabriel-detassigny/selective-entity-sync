#!/bin/sh
# Creates realistic demo content to export: pages with a child, posts using
# image, gallery and synced-pattern blocks, nested categories and a scheduled
# post. Images come from the WordPress test data bundled with wp-env.
#
# Usage: npm run demo:source   (source site)
#        npm run demo:target   (target site, e.g. to test slug matching or updates)
set -e

IMAGES="${WP_TESTS_DIR:-/wordpress-phpunit}/data/images"
if [ ! -d "$IMAGES" ]; then
	echo "WordPress test images not found in $IMAGES." >&2
	exit 1
fi

if [ -n "$(wp post list --post_type=page --name=pricing --post_status=any --field=ID)" ]; then
	echo "Demo content already exists (a 'pricing' page was found). Nothing to do."
	exit 0
fi

I1=$(wp media import "$IMAGES/canola.jpg" --title="Canola fields" --alt="Yellow canola fields under a blue sky" --porcelain)
I2=$(wp media import "$IMAGES/2004-07-22-DSC_0007.jpg" --title="Workshop" --porcelain)
I3=$(wp media import "$IMAGES/33772.jpg" --title="Studio" --porcelain)
C1=$(wp term create category "Products" --porcelain)
C2=$(wp term create category "Spring 2026" --parent="$C1" --porcelain)
PATTERN=$(wp post create --post_type=wp_block --post_status=publish --post_title="Newsletter signup" \
	--post_content='<!-- wp:paragraph --><p>Get the latest news in your inbox.</p><!-- /wp:paragraph -->' --porcelain)

PRICING=$(wp post create --post_type=page --post_status=publish --post_title="Pricing" --post_name=pricing \
	--post_content='<!-- wp:paragraph --><p>Simple plans for every team.</p><!-- /wp:paragraph -->' --porcelain)
wp post meta set "$PRICING" _thumbnail_id "$I1" > /dev/null
wp post create --post_type=page --post_status=draft --post_title="Enterprise plan" --post_parent="$PRICING" --porcelain > /dev/null

I2_URL=$(wp post get "$I2" --field=guid)
SPRING=$(wp post create --post_status=publish --post_title="Introducing our spring collection" --post_category="$C2" \
	--post_content="<!-- wp:image {\"id\":$I2} --><figure class=\"wp-block-image\"><img src=\"$I2_URL\" class=\"wp-image-$I2\"/></figure><!-- /wp:image --><!-- wp:block {\"ref\":$PATTERN} /-->" --porcelain)
wp post meta set "$SPRING" _thumbnail_id "$I2" > /dev/null

wp post create --post_status=publish --post_title="Behind the scenes at the studio" --post_category="$C1" \
	--post_content="<!-- wp:gallery {\"ids\":[$I1,$I3]} --><figure class=\"wp-block-gallery\"></figure><!-- /wp:gallery -->" --porcelain > /dev/null
NEXT_MONTH=$(wp eval 'echo gmdate( "Y-m-d 09:00:00", strtotime( "+1 month" ) );')
wp post create --post_status=future --post_date="$NEXT_MONTH" \
	--post_title="Autumn sale preview" --porcelain > /dev/null

echo "Demo content created. Open Tools → Selective Entity Sync → Export."
