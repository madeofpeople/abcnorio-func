import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

// Mirrors FieldControl.astro
function PreviewField({ field }) {
    const inputId = `form-field-${field.name}`;

    return (
        <div className="field">
            <label htmlFor={inputId}>{field.label}</label>
            {field.type === 'textarea' && (
                <textarea id={inputId} rows="6" placeholder={field.placeholder || undefined} disabled />
            )}
            {field.type === 'select' && (
                <select id={inputId} disabled>
                    {field.allow_null && <option value="">Select...</option>}
                    {field.options.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </select>
            )}
            {field.type !== 'textarea' && field.type !== 'select' && (
                <input id={inputId} type={field.type} placeholder={field.placeholder || undefined} disabled />
            )}
        </div>
    );
}

// Mirrors the fieldBlocks grouping in FormShell.astro
function groupFields(fields) {
    const blocks = [];
    fields.forEach((field) => {
        const group = field.group || '';
        const last = blocks[blocks.length - 1];
        if (group && last && last.group === group) {
            last.fields.push(field);
        } else {
            blocks.push({ group, fields: [field] });
        }
    });
    return blocks;
}

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
                {selectedForm ? (
                    <section className="abcnorio-form">
                        <div className="abcnorio-form__preview-overlay">
                            <span>Preview only: form is not active in the editor</span>
                        </div>
                        <form onSubmit={(event) => event.preventDefault()}>
                            {groupFields(selectedForm.fields).map((block, index) => (
                                block.group ? (
                                    <fieldset className="field-group" key={index}>
                                        <legend>{block.group}</legend>
                                        {block.fields.map((field) => <PreviewField field={field} key={field.name} />)}
                                    </fieldset>
                                ) : (
                                    <PreviewField field={block.fields[0]} key={index} />
                                )
                            ))}
                            <button className="button reversed" type="button" disabled>{selectedForm.submit_label}</button>
                        </form>
                    </section>
                ) : (
                    <p>Select a configured ACF public form.</p>
                )}
            </div>
        );
    },
    save: () => null,
});