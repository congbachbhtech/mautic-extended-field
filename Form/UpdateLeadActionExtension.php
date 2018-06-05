<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Form;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\LeadBundle\Form\Type\UpdateLeadActionType;
use Mautic\LeadBundle\Helper\FormFieldHelper;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Class UpdateLeadActionExtension.
 *
 * Overrides the MauticLeadBundle:UpdateLeadAction Form Type
 * so that extendedField objects are included in Field list
 */
class UpdateLeadActionExtension extends AbstractTypeExtension
{
    /** @var MauticFactory */
    private $factory;

    /**
     * @param MauticFactory $factory
     */
    public function __construct(MauticFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Returns the name of the type being extended.
     *
     * @return string The name of the type being extended
     */
    public function getExtendedType()
    {
        // use FormType::class to modify (nearly) every field in the system
        return UpdateLeadActionType::class;
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        /** @var \Mautic\LeadBundle\Model\FieldModel $fieldModel */
        $fieldModel = $this->factory->getModel('lead.field');
        $leadFields = $fieldModel->getEntities(
            [
                'force'          => [
                    [
                        'column' => 'f.isPublished',
                        'expr'   => 'eq',
                        'value'  => true,
                    ],
                ],
                'hydration_mode' => 'HYDRATE_ARRAY',
            ]
        );

        $options['fields']                      = $leadFields;
        $options['ignore_required_constraints'] = true;

        $this->getExtendedFormFields($builder, $options);
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     * @param string               $object
     */
    private function getExtendedFormFields(FormBuilderInterface $builder, array $options, $object = 'lead')
    {
        $fieldValues = [];
        $isObject    = false;
        if (!empty($options['data'])) {
            $isObject    = is_object($options['data']);
            $fieldValues = ($isObject) ? $options['data']->getFields() : $options['data'];
        }
        $mapped = !$isObject;

        foreach ($options['fields'] as $field) {
            // todo Add permission check here for access to fields to override for Secure fields

            if (false === $field['isPublished']) {
                continue;
            }
            $attr       = ['class' => 'form-control'];
            $properties = $field['properties'];
            $type       = $field['type'];
            $required   = ($isObject) ? $field['isRequired'] : false;
            $alias      = $field['alias'];
            $group      = $field['group'];

            if ($field['isUniqueIdentifer']) {
                $attr['data-unique-identifier'] = $field['alias'];
            }

            if ($isObject) {
                $value = (isset($fieldValues[$group][$alias]['value'])) ?
                    $fieldValues[$group][$alias]['value'] : $field['defaultValue'];
            } else {
                $value = (isset($fieldValues[$alias])) ? $fieldValues[$alias] : '';
            }

            $constraints = [];
            if ($required && empty($options['ignore_required_constraints'])) {
                $constraints[] = new NotBlank(
                    ['message' => 'mautic.lead.customfield.notblank']
                );
            }

            switch ($type) {
                case 'number':

                    if ('' === $value) {
                        // Prevent transform errors
                        $value = null;
                    }

                    $builder->add(
                        $alias,
                        $type,
                        [
                            'required'      => $required,
                            'label'         => $field['label'],
                            'label_attr'    => ['class' => 'control-label'],
                            'attr'          => $attr,
                            'data'          => (null !== $value) ? (float) $value : $value,
                            'mapped'        => $mapped,
                            'constraints'   => $constraints,
                            'precision'     => isset($properties['precision']) ? (int) $properties['precision'] : 0,
                            'rounding_mode' => isset($properties['roundmode']) ? (int) $properties['roundmode'] : 0,
                        ]
                    );
                    break;
                case 'date':
                case 'datetime':
                case 'time':
                    $attr['data-toggle'] = $type;
                    $opts                = [
                        'required'    => $required,
                        'label'       => $field['label'],
                        'label_attr'  => ['class' => 'control-label'],
                        'widget'      => 'single_text',
                        'attr'        => $attr,
                        'mapped'      => $mapped,
                        'input'       => 'string',
                        'html5'       => false,
                        'constraints' => $constraints,
                    ];

                    if ($value) {
                        try {
                            $dtHelper = new DateTimeHelper($value, null, 'local');
                        } catch (\Exception $e) {
                            // Rather return empty value than break the page
                            $value = null;
                        }
                    }

                    if ('datetime' == $type) {
                        $opts['model_timezone'] = 'UTC';
                        $opts['view_timezone']  = date_default_timezone_get();
                        $opts['format']         = 'yyyy-MM-dd HH:mm:ss';
                        $opts['with_seconds']   = true;

                        $opts['data'] = (!empty($value)) ? $dtHelper->toLocalString('Y-m-d H:i:s') : null;
                    } elseif ('date' == $type) {
                        $opts['data'] = (!empty($value)) ? $dtHelper->toLocalString('Y-m-d') : null;
                    } else {
                        $opts['model_timezone'] = 'UTC';
                        $opts['with_seconds']   = true;
                        $opts['view_timezone']  = date_default_timezone_get();
                        $opts['data']           = (!empty($value)) ? $dtHelper->toLocalString('H:i:s') : null;
                    }

                    $builder->addEventListener(
                        FormEvents::PRE_SUBMIT,
                        function (FormEvent $event) use ($alias, $type) {
                            $data = $event->getData();

                            if (!empty($data[$alias])) {
                                if (false === ($timestamp = strtotime($data[$alias]))) {
                                    $timestamp = null;
                                }
                                if ($timestamp) {
                                    $dtHelper = new DateTimeHelper(date('Y-m-d H:i:s', $timestamp), null, 'local');
                                    switch ($type) {
                                        case 'datetime':
                                            $data[$alias] = $dtHelper->toLocalString('Y-m-d H:i:s');
                                            break;
                                        case 'date':
                                            $data[$alias] = $dtHelper->toLocalString('Y-m-d');
                                            break;
                                        case 'time':
                                            $data[$alias] = $dtHelper->toLocalString('H:i:s');
                                            break;
                                    }
                                }
                            }
                            $event->setData($data);
                        }
                    );

                    $builder->add($alias, $type, $opts);
                    break;
                case 'select':
                case 'multiselect':
                case 'boolean':
                    $typeProperties = [
                        'required'    => $required,
                        'label'       => $field['label'],
                        'label_attr'  => ['class' => 'control-label'],
                        'attr'        => $attr,
                        'mapped'      => $mapped,
                        'multiple'    => false,
                        'constraints' => $constraints,
                    ];

                    $choiceType = 'choice';
                    $emptyValue = '';
                    if (in_array($type, ['select', 'multiselect']) && !empty($properties['list'])) {
                        $typeProperties['choices']  = FormFieldHelper::parseList($properties['list']);
                        $typeProperties['expanded'] = false;
                        $typeProperties['multiple'] = ('multiselect' === $type);
                    }
                    if ('boolean' == $type && !empty($properties['yes']) && !empty($properties['no'])) {
                        $choiceType                  = 'yesno_button_group';
                        $typeProperties['expanded']  = true;
                        $typeProperties['yes_label'] = $properties['yes'];
                        $typeProperties['no_label']  = $properties['no'];
                        $typeProperties['attr']      = [];
                        $emptyValue                  = ' x ';
                        if ('' !== $value && null !== $value) {
                            $value = (int) $value;
                        }
                    }

                    $typeProperties['data']        = 'multiselect' === $type ? FormFieldHelper::parseList(
                        $value
                    ) : $value;
                    $typeProperties['empty_value'] = $emptyValue;
                    $builder->add(
                        $alias,
                        $choiceType,
                        $typeProperties
                    );
                    break;
                case 'country':
                case 'region':
                case 'timezone':
                case 'locale':
                    switch ($type) {
                        case 'country':
                            $choices = FormFieldHelper::getCountryChoices();
                            break;
                        case 'region':
                            $choices = FormFieldHelper::getRegionChoices();
                            break;
                        case 'timezone':
                            $choices = FormFieldHelper::getTimezonesChoices();
                            break;
                        case 'locale':
                            $choices = FormFieldHelper::getLocaleChoices();
                            break;
                    }

                    $builder->add(
                        $alias,
                        'choice',
                        [
                            'choices'     => $choices,
                            'required'    => $required,
                            'label'       => $field['label'],
                            'label_attr'  => ['class' => 'control-label'],
                            'data'        => $value,
                            'attr'        => [
                                'class'            => 'form-control',
                                'data-placeholder' => $field['label'],
                            ],
                            'mapped'      => $mapped,
                            'multiple'    => false,
                            'expanded'    => false,
                            'constraints' => $constraints,
                        ]
                    );
                    break;
                default:
                    $attr['data-encoding'] = 'raw';
                    switch ($type) {
                        case 'lookup':
                            $type                = 'text';
                            $attr['data-toggle'] = 'field-lookup';
                            $attr['data-action'] = 'lead:fieldList';
                            $attr['data-target'] = $alias;

                            if (!empty($properties['list'])) {
                                $attr['data-options'] = FormFieldHelper::formatList(
                                    FormFieldHelper::FORMAT_BAR,
                                    array_keys(FormFieldHelper::parseList($properties['list']))
                                );
                            }
                            break;
                        case 'email':
                            // Enforce a valid email
                            $attr['data-encoding'] = 'email';
                            $constraints[]         = new Email(
                                [
                                    'message' => 'mautic.core.email.required',
                                ]
                            );
                            break;
                    }

                    $builder->add(
                        $alias,
                        $type,
                        [
                            'required'   => $field['isRequired'],
                            'label'      => $field['label'],
                            'label_attr' => ['class' => 'control-label'],

                            'attr'        => $attr,
                            'data'        => $value,
                            'mapped'      => $mapped,
                            'constraints' => $constraints,
                        ]
                    );
                    break;
            }
        }
    }
}
