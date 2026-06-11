import 'abcnorio-webcomponents/event-teaser/element';
import { buildEventTeaserPayload } from 'abcnorio-webcomponents/event-teaser/payload';

const { wp } = window;

if (wp?.plugins && wp?.element && wp?.data && wp?.editPost) {
    const { registerPlugin } = wp.plugins;
    const { createElement } = wp.element;
    const { useSelect } = wp.data;
    const { PluginDocumentSettingPanel } = wp.editPost;

    const buildFeaturedImage = (media) => {
        if (!media || !media.source_url) {
            return null;
        }

        const mediaDetails = media.media_details || {};
        const cardSize = mediaDetails.sizes && mediaDetails.sizes['abcnorio-card']
            ? mediaDetails.sizes['abcnorio-card']
            : null;

        return {
            url: cardSize && cardSize.source_url ? cardSize.source_url : media.source_url,
            alt: media.alt_text || '',
            width: cardSize && cardSize.width ? cardSize.width : mediaDetails.width || 290,
            height: cardSize && cardSize.height ? cardSize.height : mediaDetails.height || 9999,
        };
    };

    function EventTeaserPreviewPanel() {
        const previewState = useSelect((select) => {
            const editorStore = select('core/editor');
            const coreStore = select('core');

            if (!editorStore || !coreStore) {
                return null;
            }

            const postType = editorStore.getCurrentPostType();

            if (postType !== 'event') {
                return null;
            }

            const meta = editorStore.getEditedPostAttribute('meta') || {};
            const featuredMediaId = editorStore.getEditedPostAttribute('featured_media');
            const featuredMedia = featuredMediaId
                ? coreStore.getEntityRecord('postType', 'attachment', featuredMediaId)
                : null;

            return {
                attributes: {
                    slug: editorStore.getEditedPostAttribute('slug') || '',
                    title: { rendered: editorStore.getEditedPostAttribute('title') || '' },
                    excerpt: { rendered: editorStore.getEditedPostAttribute('excerpt') || '' },
                    event_start_date: meta.event_start_date || '',
                    event_end_date: meta.event_end_date || '',
                    event_effective_end: meta.event_effective_end || meta.event_end_date || '',
                    featured_image: buildFeaturedImage(featuredMedia),
                    showTeaser: true,
                    priority: false,
                },
                featuredMediaReady: !featuredMediaId || featuredMedia !== undefined,
            };
        }, []);

        if (!previewState) {
            return null;
        }

        if (!previewState.featuredMediaReady) {
            return createElement(
                PluginDocumentSettingPanel,
                {
                    name: 'abcnorio-event-teaser-preview',
                    title: 'Event teaser preview',
                    className: 'event-teaser-preview-panel',
                },
                createElement('p', null, 'Loading event teaser preview...')
            );
        }

        const payload = buildEventTeaserPayload(previewState.attributes, { hrefBase: '/events' });
        const payloadJson = JSON.stringify(payload).replace(/</g, '\\u003c');

        return createElement(
            PluginDocumentSettingPanel,
            {
                name: 'abcnorio-event-teaser-preview',
                title: 'Event teaser preview',
                className: 'event-teaser-preview-panel',
            },
            createElement(
                'event-teaser',
                {
                    key: payloadJson,
                    className: `events-wc-teaser${payload.isPastEvent ? ' past' : ''}`,
                    'data-component': 'abcnorio/event-teaser',
                },
                createElement('script', {
                    type: 'application/json',
                    className: 'events-wc-teaser__payload',
                    dangerouslySetInnerHTML: { __html: payloadJson },
                })
            )
        );
    }

    registerPlugin('abcnorio-event-teaser-preview', { render: EventTeaserPreviewPanel });
}