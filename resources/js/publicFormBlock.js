import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

registerBlockType('abcnorio/forms', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps();
        const [forms, setForms] = useState([]);
        const [error, setError] = useState('');
        const [isLoading, setIsLoading] = useState(true);
        const formSlug = String(attributes.formSlug || '');
        const selectedForm = forms.find((form) => form.slug === formSlug);
        const options = [
            { label: 'Select a public form', value: '' },
            ...forms.map((form) => ({
                label: `${form.title} (${form.slug})`,
                value: form.slug,
            })),
        ];

        useEffect(() => {
            let isMounted = true;

            apiFetch({ path: '/abcnorio/v1/forms' })
                .then((result) => {
                    if (isMounted) {
                        setForms(Array.isArray(result) ? result : []);
                    }
                })
                .catch((requestError) => {
                    if (isMounted) {
                        setError(String(requestError?.message || 'Could not load public forms.'));
                    }
                })
                .finally(() => {
                    if (isMounted) {
                        setIsLoading(false);
                    }
                });

            return () => {
                isMounted = false;
            };
        }, []);

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="ABC No Rio Forms" initialOpen={true}>
                        {isLoading ? (
                            <Spinner />
                        ) : (
                            <SelectControl
                                label="Configured ACF form"
                                value={formSlug}
                                options={options}
                                onChange={(value) => setAttributes({ formSlug: value })}
                            />
                        )}
                        {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
                    </PanelBody>
                </InspectorControls>
                <p>{selectedForm ? `Form: ${selectedForm.title}` : 'Select a configured ACF public form.'}</p>
            </div>
        );
    },
    save: () => null,
});