import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
    PanelBody,
    SelectControl,
    TextControl,
    ToggleControl,
    CheckboxControl,
    Spinner,
    Notice,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

function toStringArray(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((entry) => String(entry || '').trim())
        .filter(Boolean);
}

registerBlockType('abcnorio/newsletter-signup', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps();
        const [isLoading, setIsLoading] = useState(false);
        const [error, setError] = useState('');
        const [listData, setListData] = useState({ merge_fields: [], interest_groups: [] });

        const pluginData = window.mailchimp_sf_block_data || {};
        const lists = Array.isArray(pluginData.lists) ? pluginData.lists : [];
        const listOptions = [
            { label: 'Select a list', value: '' },
            ...lists.map((list) => ({
                label: String(list?.name || ''),
                value: String(list?.id || ''),
            })),
        ];

        const selectedListId = String(attributes.listId || pluginData.list_id || '').trim();

        useEffect(() => {
            if (!selectedListId) {
                setListData({ merge_fields: [], interest_groups: [] });
                return;
            }

            let isMounted = true;
            setIsLoading(true);
            setError('');

            apiFetch({ path: `/abcnorio/v1/mailchimp/list-data/${selectedListId}` })
                .then((payload) => {
                    if (!isMounted) {
                        return;
                    }

                    const mergeFields = Array.isArray(payload?.merge_fields) ? payload.merge_fields : [];
                    const interestGroups = Array.isArray(payload?.interest_groups) ? payload.interest_groups : [];
                    setListData({ merge_fields: mergeFields, interest_groups: interestGroups });

                    const requiredTags = mergeFields
                        .filter((field) => field?.required)
                        .map((field) => String(field?.tag || '').trim())
                        .filter(Boolean);

                    const defaultTags = mergeFields
                        .filter((field) => String(field?.tag || '').trim() === 'EMAIL')
                        .map((field) => String(field?.tag || '').trim())
                        .filter(Boolean);

                    const currentTags = toStringArray(attributes.fieldTags);
                    if (currentTags.length === 0) {
                        setAttributes({ fieldTags: defaultTags });
                    } else {
                        const withRequired = Array.from(new Set([...currentTags, ...requiredTags]));
                        if (withRequired.length !== currentTags.length) {
                            setAttributes({ fieldTags: withRequired });
                        }
                    }

                    const visibleGroupIds = interestGroups
                        .filter((group) => String(group?.type || '') !== 'hidden')
                        .map((group) => String(group?.id || '').trim())
                        .filter(Boolean);

                    const currentGroupIds = toStringArray(attributes.groupIds);
                    if (currentGroupIds.some((id) => !visibleGroupIds.includes(id))) {
                        setAttributes({ groupIds: currentGroupIds.filter((id) => visibleGroupIds.includes(id)) });
                    }
                })
                .catch((err) => {
                    if (!isMounted) {
                        return;
                    }

                    setError(String(err?.message || 'Failed to fetch Mailchimp list data.'));
                })
                .finally(() => {
                    if (isMounted) {
                        setIsLoading(false);
                    }
                });

            return () => {
                isMounted = false;
            };
        }, [selectedListId]);

        const requiredTagSet = useMemo(() => {
            const requiredTags = (listData.merge_fields || [])
                .filter((field) => field?.required)
                .map((field) => String(field?.tag || '').trim())
                .filter(Boolean);

            return new Set(requiredTags);
        }, [listData]);

        const selectedFieldTags = toStringArray(attributes.fieldTags);
        const selectedGroupIds = toStringArray(attributes.groupIds);

        const toggleFieldTag = (tag, checked) => {
            const current = toStringArray(attributes.fieldTags);
            const next = checked
                ? Array.from(new Set([...current, tag]))
                : current.filter((entry) => entry !== tag);

            if (!checked && requiredTagSet.has(tag)) {
                return;
            }

            setAttributes({ fieldTags: next });
        };

        const toggleGroupId = (id, checked) => {
            const current = toStringArray(attributes.groupIds);
            const next = checked
                ? Array.from(new Set([...current, id]))
                : current.filter((entry) => entry !== id);

            setAttributes({ groupIds: next });
        };

        const previewFields = (listData.merge_fields || [])
            .filter((field) => selectedFieldTags.includes(String(field?.tag || '').trim()));

        const previewGroups = (listData.interest_groups || [])
            .filter((group) => selectedGroupIds.includes(String(group?.id || '').trim()));

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="Newsletter Signup" initialOpen={true}>
                        <SelectControl
                            label="Mailchimp List"
                            value={selectedListId}
                            options={listOptions}
                            onChange={(value) => setAttributes({ listId: value })}
                        />
                        <TextControl
                            label="Header"
                            value={attributes.header || ''}
                            onChange={(value) => setAttributes({ header: value })}
                        />
                        <TextControl
                            label="Sub Header"
                            value={attributes.subHeader || ''}
                            onChange={(value) => setAttributes({ subHeader: value })}
                        />
                        <TextControl
                            label="Submit Button Text"
                            value={attributes.submitText || 'Subscribe'}
                            onChange={(value) => setAttributes({ submitText: value })}
                        />
                        <TextControl
                            label="Required Indicator Text"
                            value={attributes.requiredIndicatorText || '* = required field'}
                            onChange={(value) => setAttributes({ requiredIndicatorText: value })}
                        />
                        <ToggleControl
                            label="Show Required Indicator"
                            checked={attributes.showRequiredIndicator !== false}
                            onChange={(value) => setAttributes({ showRequiredIndicator: value })}
                        />
                        <ToggleControl
                            label="Double Opt In"
                            checked={attributes.doubleOptIn !== false}
                            onChange={(value) => setAttributes({ doubleOptIn: value })}
                        />
                        <ToggleControl
                            label="Update Existing Subscribers"
                            checked={attributes.updateExistingSubscribers !== false}
                            onChange={(value) => setAttributes({ updateExistingSubscribers: value })}
                        />
                    </PanelBody>

                    <PanelBody title="Visible Fields" initialOpen={false}>
                        {isLoading && <Spinner />}
                        {!isLoading && listData.merge_fields.length === 0 && <p>No list fields available.</p>}
                        {!isLoading && listData.merge_fields.map((field) => {
                            const tag = String(field?.tag || '').trim();
                            if (!tag) {
                                return null;
                            }

                            const label = String(field?.name || tag);
                            const required = requiredTagSet.has(tag);
                            const checked = required || selectedFieldTags.includes(tag);

                            return (
                                <CheckboxControl
                                    key={tag}
                                    label={required ? `${label} (required)` : label}
                                    checked={checked}
                                    disabled={required}
                                    onChange={(value) => toggleFieldTag(tag, value)}
                                />
                            );
                        })}
                    </PanelBody>

                    <PanelBody title="Visible Audience Groups" initialOpen={false}>
                        {isLoading && <Spinner />}
                        {!isLoading && listData.interest_groups.length === 0 && <p>No audience groups available.</p>}
                        {!isLoading && listData.interest_groups
                            .filter((group) => String(group?.type || '') !== 'hidden')
                            .map((group) => {
                                const id = String(group?.id || '').trim();
                                if (!id) {
                                    return null;
                                }

                                const label = String(group?.title || id);
                                const checked = selectedGroupIds.includes(id);

                                return (
                                    <CheckboxControl
                                        key={id}
                                        label={label}
                                        checked={checked}
                                        onChange={(value) => toggleGroupId(id, value)}
                                    />
                                );
                            })}
                    </PanelBody>
                </InspectorControls>

                {error && (
                    <Notice status="error" isDismissible={false}>
                        {error}
                    </Notice>
                )}

                <div className="abcnorio-newsletter-signup__preview">
                    <h3 className="abcnorio-newsletter-signup__preview-title">
                        {attributes.header || 'Newsletter Signup'}
                    </h3>
                    {attributes.subHeader && (
                        <p className="abcnorio-newsletter-signup__preview-subtitle">{attributes.subHeader}</p>
                    )}

                    {previewFields.map((field) => {
                        const tag = String(field?.tag || '').trim();
                        const label = String(field?.name || tag);
                        const required = Boolean(field?.required);

                        return (
                            <div key={tag} className="abcnorio-newsletter-signup__preview-field">
                                <label className="abcnorio-newsletter-signup__preview-label">
                                    {label}
                                    {required && attributes.showRequiredIndicator !== false ? ' *' : ''}
                                </label>
                                <div className="abcnorio-newsletter-signup__preview-input" />
                            </div>
                        );
                    })}

                    {previewGroups.map((group) => (
                        <div key={String(group?.id || '')} className="abcnorio-newsletter-signup__group">
                            <p className="abcnorio-newsletter-signup__group-title">{String(group?.title || '')}</p>
                        </div>
                    ))}

                    {attributes.showRequiredIndicator !== false && (
                        <p className="abcnorio-newsletter-signup__required">
                            {attributes.requiredIndicatorText || '* = required field'}
                        </p>
                    )}

                    <button type="button" className="abcnorio-newsletter-signup__preview-button" disabled>
                        {attributes.submitText || 'Subscribe'}
                    </button>
                </div>
            </div>
        );
    },
    save() {
        return null;
    },
});
