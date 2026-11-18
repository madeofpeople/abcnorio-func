import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ComponentShell from './componentShell';

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
            ...(formSlug && !selectedForm ? [{ label: `Saved selection (${formSlug})`, value: formSlug }] : []),
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
                        if (!Array.isArray(result) || result.some((form) =>
                            !form || typeof form.slug !== 'string' || !form.slug.trim()
                            || typeof form.title !== 'string'
                            || (form.submit_label !== undefined && typeof form.submit_label !== 'string')
                        )) {
                            throw new Error('Could not load public forms: invalid response.');
                        }
                        setForms(result);
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
                <ComponentShell
                    component="public-form"
                    blockProps={{}}
                    bindings={{
                        title: { children: selectedForm ? `Form: ${selectedForm.title}` : 'Public form' },
                        slug: { hidden: !formSlug, children: formSlug },
                        status: { children: isLoading
                            ? 'Loading public forms...'
                            : error
                                ? error
                                : formSlug && !selectedForm
                                    ? 'The selected form is no longer available. Choose another form in the block settings.'
                                    : selectedForm
                                        ? 'Configured form selected.'
                                        : forms.length === 0
                                            ? 'No public forms are configured.'
                                            : 'Select a configured ACF public form.',
                        },
                        submit: { hidden: !selectedForm, children: selectedForm?.submit_label || 'Submit' },
                    }}
                />
            </div>
        );
    },
    save: () => null,
});