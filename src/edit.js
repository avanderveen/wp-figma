import apiFetch from '@wordpress/api-fetch'
import { useBlockProps } from '@wordpress/block-editor'
import {
  Flex,
  FlexItem,
	SelectControl,
  Placeholder,
} from '@wordpress/components'
import { useSelect } from '@wordpress/data';
import { BlockControls, InspectorControls } from '@wordpress/editor'
import { createContext, useEffect, useState } from '@wordpress/element'
import PropTypes from 'prop-types'

import './editor.scss'

const FIGMA_TEAM = "1104887045692325762"

const useFigmaFetch = (
  path,
  method = 'GET',
  deps = [path]
) => {
  const [response, setResponse] = useState({ isLoading: true })

  useEffect(() => {
    const executeRequest = async () => {
      const result = await apiFetch({
        method,
        path: `/index.php?rest_route=/wp-figma/v1/${path}`
      })
      setResponse({ isLoading: false, data: { ...result } })
    }

    executeRequest()
  }, deps)

  return response
}

const useFigmaTeam = (teamId) => useFigmaFetch(`teams/${teamId}`)

const useFigmaImages = (fileId) => useFigmaFetch(`files/${fileId}/images?format=svg`)

const useFigmaAttachment = (fileId, imageId, postId) => useFigmaFetch(
  `files/${fileId}/images/${imageId}/posts/${postId}?format=svg`,
  'POST'
)

const SelectionContext = createContext({
  team: undefined,
  project: -1,
  setProject: () => {},
  file: -1,
  setFile: () => {},
  frame: {},
  setFrame: () => {}
})

const ImageSelect = ({ fileId }) => {
  const { data: file = { frames: [] } } = useFigmaImages(fileId)
  return (
    <SelectionContext.Consumer>
    {({ setFrame }) => (
      <Flex className="image-select">
      {((file || {}).frames || []).map((frame) => (
        <FlexItem key={frame.id} onClick={() => setFrame(frame)}>
          <img src={frame.image}/>
          <div>Frame: {frame.name}</div>
        </FlexItem>
      ))}
      </Flex>
    )}
    </SelectionContext.Consumer>
  )
}

ImageSelect.propTypes = {
  fileId: PropTypes.string,
  setFrame: PropTypes.func
}

const ImageEditor = ({ fileId }) => (
  <SelectionContext.Consumer>
  {({ frame }) => frame
    ? <img src={frame.image}/>
    : <ImageSelect fileId={fileId}/>
  }
  </SelectionContext.Consumer>
)

ImageEditor.propTypes = {
  fileId: PropTypes.string
}

const FileSelect = () => {
  const defaultOption = (type) => ({ label: `Select ${type}`, value: -1 })
  const options = (type, array = []) => [defaultOption(type)].concat(
    array.map((element) => ({ label: element.name, value: element.id }))
  )

  return (
    <SelectionContext.Consumer>
    {({ team, project, setProject, file, setFile }) => {
      const projects = team.projects || []
      const projectOptions = options("Project", projects)
      const fileOptions = project === -1
        ? [{label: "No Project Selected", value: -1}]
        : options("Files", projects.find((p) => p.id === project).files)

      return (
        <>
          <SelectControl
            label="Project"
            value={project}
            options={projectOptions}
            onChange={setProject}
          />
          <SelectControl
            label="File"
            value={file}
            options={fileOptions}
            onChange={setFile}
          />
        </>
      )
    }}
    </SelectionContext.Consumer>
  )
}

const ImageControls = () => (
  <SelectionContext.Consumer>
  {({ frame }) => frame
    ? null
    : <FileSelect/>
  }
  </SelectionContext.Consumer>
)

const EditFigmaAttachment = ({ fileId, frameId }) => {
  const postId = useSelect(select => select("core/editor").getCurrentPostId())
  const { isLoading, data } = useFigmaAttachment(fileId, frameId, postId)
  console.log({isLoading, data})
  return isLoading ? null : <div>{`Attachment :${data.attachmentId}`}</div>
}

EditFigmaAttachment.propTypes = {
  fileId: PropTypes.string,
  frameId: PropTypes.string
}

const Edit = () => {
  const { data: team = {} } = useFigmaTeam(FIGMA_TEAM)
  const [project, setProject] = useState(-1)
  const [file, setFile] = useState(-1)
  const [frame, setFrame] = useState(null)

  return (
    <SelectionContext.Provider value={{
      team,
      project,
      setProject,
      file,
      setFile,
      frame,
      setFrame
    }}>
      <InspectorControls>
        <ImageControls/>
      </InspectorControls>
      <BlockControls>
      </BlockControls>
      <div {...useBlockProps()}>
        <Placeholder icon="cover-image" label="Select Figma Content">
          { file && file !== -1 && (
              frame !== null
                ? <EditFigmaAttachment fileId={file} frameId={frame.id}/>
                : <ImageEditor fileId={file}/>
            )
          }
        </Placeholder>
      </div>
   </SelectionContext.Provider>
  )
}

export default Edit
