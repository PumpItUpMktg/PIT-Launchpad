<?php
/**
 * The jobs grid — rendered by [pig_jobs] and the launchpad/pig-jobs block. Runs in
 * {@see \PIG\Jobs\Render::render()}'s scope, where $query is the WP_Query of published pig_job posts.
 *
 * @package PIG\Jobs
 *
 * @var \WP_Query $query
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! $query->have_posts()) {
    echo '<p class="pig-jobs-empty">' . esc_html__('No recent jobs to show yet.', 'pig-jobs') . '</p>';

    return;
}

static $printed_styles = false;
if (! $printed_styles) {
    $printed_styles = true;
    echo '<style>
        .pig-jobs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;margin:1.5rem 0}
        .pig-job-card{border:1px solid rgba(0,0,0,.08);border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
        .pig-job-thumb{display:block;aspect-ratio:4/3;background:#f1f5f9;overflow:hidden}
        .pig-job-thumb img{width:100%;height:100%;object-fit:cover}
        .pig-job-body{padding:14px 16px;display:flex;flex-direction:column;gap:6px}
        .pig-job-title{margin:0;font-size:1.05rem;line-height:1.3}
        .pig-job-title a{text-decoration:none;color:inherit}
        .pig-job-city{margin:0;font-size:.8rem;text-transform:uppercase;letter-spacing:.03em;opacity:.7}
        .pig-job-excerpt{margin:0;font-size:.92rem;opacity:.85}
    </style>';
}
?>
<div class="pig-jobs-grid">
    <?php
    while ($query->have_posts()) :
        $query->the_post();
        ?>
        <article class="pig-job-card">
            <a href="<?php the_permalink(); ?>" class="pig-job-thumb">
                <?php if (has_post_thumbnail()) {
                    the_post_thumbnail('medium_large');
                } ?>
            </a>
            <div class="pig-job-body">
                <h3 class="pig-job-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                <?php $cities = get_the_term_list(get_the_ID(), \PIG\Jobs\Cpt::TAX_CITY, '', ', '); ?>
                <?php if ($cities && ! is_wp_error($cities)) : ?>
                    <p class="pig-job-city"><?php echo wp_kses_post($cities); ?></p>
                <?php endif; ?>
                <?php $excerpt = get_the_excerpt(); ?>
                <?php if ($excerpt !== '') : ?>
                    <p class="pig-job-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 24)); ?></p>
                <?php endif; ?>
            </div>
        </article>
    <?php endwhile; ?>
</div>
