import { registerBlockType } from '@wordpress/blocks';
import {
    BlockControls,
    InnerBlocks,
    MediaPlaceholder,
    MediaUpload,
    MediaUploadCheck,
    useBlockProps,
} from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';

const TEMPLATE = [
    ['core/heading', { level: 2, placeholder: 'Write title...' }],
    ['core/paragraph', { placeholder: 'Write text...' }],
    ['core/buttons', {}, [['core/button', { text: 'Learn More' }]]],
];

registerBlockType('abcnorio/hero', {
    edit({ attributes, setAttributes }) {
        const imageUrl = String(attributes.url || '').trim();

        const onSelectImage = (media) => {
            setAttributes({
                id: Number(media?.id || 0),
                url: String(media?.url || ''),
                alt: String(media?.alt || ''),
            });
        };

        const onRemoveImage = () => {
            setAttributes({
                id: 0,
                url: '',
                alt: '',
            });
        };

        const style = imageUrl === ''
            ? {
                '--cover-bg-xs': 'none',
                '--cover-bg-sm': 'none',
                '--cover-bg-md': 'none',
                '--cover-bg-lg': 'none',
            }
            : {
                '--cover-bg-xs': `url("${imageUrl}")`,
                '--cover-bg-sm': `url("${imageUrl}")`,
                '--cover-bg-md': `url("${imageUrl}")`,
                '--cover-bg-lg': `url("${imageUrl}")`,
            };

        const blockProps = useBlockProps({ className: 'abcnorio-hero' });

        return (
            <div {...blockProps}>
                <BlockControls group="block">
                    <ToolbarGroup>
                        <MediaUploadCheck>
                            <MediaUpload
                                onSelect={onSelectImage}
                                allowedTypes={['image']}
                                value={attributes.id || 0}
                                render={({ open }) => (
                                    <ToolbarButton
                                        icon="format-image"
                                        label={imageUrl === '' ? 'Select background image' : 'Replace background image'}
                                        onClick={open}
                                    />
                                )}
                            />
                        </MediaUploadCheck>
                        {imageUrl !== '' && (
                            <ToolbarButton
                                icon="trash"
                                label="Remove background image"
                                onClick={onRemoveImage}
                            />
                        )}
                    </ToolbarGroup>
                </BlockControls>

                <div className="abcnorio-hero__image-wrapper" style={style}>
                    {imageUrl === '' && (
                        <MediaPlaceholder
                            icon="format-image"
                            labels={{ title: 'Cover background image' }}
                            onSelect={onSelectImage}
                            allowedTypes={['image']}
                        />
                    )}
                    <div className="abcnorio-hero__content-wrapper">
                        <div className="abcnorio-hero__content">
                            <InnerBlocks
                                template={TEMPLATE}
                                templateLock={false}
                                allowedBlocks={[
                                    'core/heading',
                                    'core/paragraph',
                                    'core/buttons',
                                    'core/button',
                                    'core/list',
                                    'core/list-item',
                                    'core/image',
                                ]}
                            />
                        </div>
                    </div>
                </div>
            </div>
        );
    },
    save() {
        return (
            <div className="abcnorio-hero__content-wrapper">
                <div className="abcnorio-hero__content">
                    <InnerBlocks.Content />
                </div>
            </div>
        );
    },
});
